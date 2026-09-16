/*
 * ext/proxy - generated proxy class construction
 *
 * A proxy class is a runtime generated, final user class that behaves like a
 * subclass of the proxied class (or an implementation of the proxied
 * interface). The class entry is assembled directly instead of going through
 * zend_do_inheritance() because the engine refuses to derive from final
 * classes at link time, and mutating the proxied class entry is not an option
 * (it may live in shared, read-only opcache memory).
 *
 * The generated class mirrors the proxied type's metadata (constants, static
 * members, property declarations, interfaces) but every instance method in
 * its function table is an internal trampoline that routes the call through
 * the interceptor. Static methods stay the original functions and are
 * therefore never intercepted.
 */

#include "php_proxy.h"
#include "zend_interfaces.h"

/* ZEND_ACC_DEPRECATED is deliberately not mirrored: the deprecation is
 * raised when the original method is invoked, so mirroring it would report
 * the same call twice on wrapped objects.
 *
 * ZEND_ACC_CHANGED is mirrored: it makes Zend's method resolver apply the
 * private-shadow rule (a call from an ancestor's scope selects the
 * ancestor's private method rather than a descendant's redeclaration). For a
 * direct call get_method() maps the selected function onto its trampoline.
 * Callables resolved by the engine itself ([$proxy, 'm'] handed to an
 * internal function) select the same method but call the ancestor's original
 * function directly. This narrow exclusion is approved to preserve JIT,
 * alongside the separate original-class reflection exclusion. */
#define PROXY_MIRRORED_METHOD_FLAGS (ZEND_ACC_PPP_MASK | ZEND_ACC_FINAL | ZEND_ACC_CHANGED \
	| ZEND_ACC_VARIADIC | ZEND_ACC_RETURN_REFERENCE | ZEND_ACC_HAS_TYPE_HINTS \
	| ZEND_ACC_HAS_RETURN_TYPE | ZEND_ACC_STRICT_TYPES)

#define PROXY_MIRRORED_CLASS_FLAGS (ZEND_HAS_STATIC_IN_METHODS | ZEND_ACC_HAS_TYPE_HINTS \
	| ZEND_ACC_HAS_READONLY_PROPS | ZEND_ACC_USE_GUARDS | ZEND_ACC_ALLOW_DYNAMIC_PROPERTIES \
	| ZEND_ACC_NO_DYNAMIC_PROPERTIES | ZEND_ACC_READONLY_CLASS)

/* arg_info of the variadic trampoline used for names that resolve to __call() */
static const zend_internal_arg_info proxy_dynamic_arg_info[] = {
	{ (const char *) (zend_uintptr_t) 0, ZEND_TYPE_INIT_NONE(0), NULL }, /* return info (unused) */
	{ "args", ZEND_TYPE_INIT_CODE(IS_MIXED, 0, 0), NULL },
};

/* ------------------------------------------------------------------------- */
/* Validation                                                                */
/* ------------------------------------------------------------------------- */

/* Internal interfaces come with an interface_gets_implemented() callback that
 * either wires up engine machinery the proxy replaces anyway, or forbids user
 * implementations outright (DateTimeInterface, Throwable, UnitEnum, ...).
 * Only the former group is safe to mock. */
static bool proxy_interface_is_supported(const zend_class_entry *iface)
{
	static const char *const supported[] = {
		"Traversable", "IteratorAggregate", "Iterator", "ArrayAccess",
		"Countable", "Stringable", "Serializable", "JsonSerializable",
	};

	if (iface->type == ZEND_USER_CLASS || !iface->interface_gets_implemented) {
		return true;
	}
	for (size_t i = 0; i < sizeof(supported) / sizeof(supported[0]); i++) {
		if (zend_string_equals_cstr(iface->name, supported[i], strlen(supported[i]))) {
			return true;
		}
	}
	return false;
}

zend_result proxy_class_validate_type(zend_class_entry *ce, bool for_wrap)
{
	if (ce->ce_flags & ZEND_ACC_ENUM) {
		proxy_throw(proxy_ce_UnsupportedProxyType, "Enum %s cannot be proxied", ZSTR_VAL(ce->name));
		return FAILURE;
	}
	if (ce->ce_flags & ZEND_ACC_TRAIT) {
		proxy_throw(proxy_ce_UnsupportedProxyType, "Trait %s cannot be proxied", ZSTR_VAL(ce->name));
		return FAILURE;
	}
	if (ce->create_object == proxy_create_object) {
		proxy_throw(proxy_ce_InvalidProxyTarget, "%s is a proxy class and cannot be proxied again", ZSTR_VAL(ce->name));
		return FAILURE;
	}
	if (ce->ce_flags & ZEND_ACC_INTERFACE) {
		ZEND_ASSERT(!for_wrap);
		if (!proxy_interface_is_supported(ce)) {
			proxy_throw(proxy_ce_UnsupportedProxyType,
				"Interface %s cannot be implemented by user classes and cannot be proxied",
				ZSTR_VAL(ce->name));
			return FAILURE;
		}
		for (uint32_t i = 0; i < ce->num_interfaces; i++) {
			if (!proxy_interface_is_supported(ce->interfaces[i])) {
				proxy_throw(proxy_ce_UnsupportedProxyType,
					"Interface %s extends %s, which cannot be implemented by user classes and cannot be proxied",
					ZSTR_VAL(ce->name), ZSTR_VAL(ce->interfaces[i]->name));
				return FAILURE;
			}
		}
		return SUCCESS;
	}
	if (ce->type == ZEND_INTERNAL_CLASS && (ce->ce_flags & ZEND_ACC_FINAL)) {
		/* Same rule as ReflectionClass::newInstanceWithoutConstructor(): the
		 * object layout of internal final classes cannot be created without
		 * running the constructor. */
		proxy_throw(proxy_ce_UnsupportedProxyType, "Internal final class %s cannot be proxied", ZSTR_VAL(ce->name));
		return FAILURE;
	}
	return SUCCESS;
}

/* ------------------------------------------------------------------------- */
/* Trampolines                                                               */
/* ------------------------------------------------------------------------- */

bool proxy_function_is_trampoline(const zend_function *fn)
{
	return fn->type == ZEND_INTERNAL_FUNCTION
		&& fn->internal_function.handler == proxy_method_handler;
}

static zend_string *proxy_interned_name(zend_string *name)
{
	/* zend_function_dtor() releases method names as persistent strings, so
	 * the trampoline name must be interned. Method names produced by the
	 * compiler already are; this is a safety net for exotic sources. */
	if (ZSTR_IS_INTERNED(name)) {
		return name;
	}
	return zend_new_interned_string(zend_string_copy(name));
}

/* One dispatch mechanism for every resolved instance method, including methods
 * selected by Zend's ordinary scope and visibility resolution. Trampolines
 * stored in the generated class' function table are released by
 * zend_function_dtor(), which drops their name as a persistent string: that
 * name must be interned and is referenced. A trampoline that only lives in
 * the cache is never destroyed and borrows the original's name instead
 * (zend_new_interned_string() hands back an ordinary string when opcache does
 * not intern for the current file, and nothing would release a reference). */
proxy_method *proxy_method_for_function(proxy_class *cls, zend_function *original, bool in_function_table)
{
	proxy_method *cached = zend_hash_index_find_ptr(&cls->method_cache, (zend_ulong) (uintptr_t) original);
	if (cached) {
		return cached;
	}
	proxy_method *pm = zend_arena_calloc(&CG(arena), 1, sizeof(proxy_method));
	zend_internal_function *fn = &pm->fn;

	fn->type = ZEND_INTERNAL_FUNCTION;
	memcpy(fn->arg_flags, original->common.arg_flags, sizeof(fn->arg_flags));
	fn->fn_flags = (original->common.fn_flags & PROXY_MIRRORED_METHOD_FLAGS) | ZEND_ACC_ARENA_ALLOCATED;
#ifdef ZEND_ACC_NODISCARD
	fn->fn_flags |= original->common.fn_flags & ZEND_ACC_NODISCARD;
#endif
	if (original->type == ZEND_USER_FUNCTION) {
		fn->fn_flags |= ZEND_ACC_USER_ARG_INFO;
	}
	fn->function_name = in_function_table
		? proxy_interned_name(original->common.function_name)
		: original->common.function_name;
	fn->scope = original->common.scope;
	fn->prototype = original->common.prototype;
	fn->num_args = original->common.num_args;
	fn->required_num_args = original->common.required_num_args;
	fn->arg_info = (zend_internal_arg_info *) original->common.arg_info;
	fn->attributes = original->common.attributes;
	ZEND_MAP_PTR_INIT(fn->run_time_cache, NULL);
	fn->doc_comment = NULL;
	fn->T = 0;
	fn->prop_info = NULL;
	fn->handler = proxy_method_handler;
	fn->module = NULL;
	fn->frameless_function_infos = NULL;
	fn->reserved[proxy_reserved_slot] = pm;

	pm->original = original;
	pm->cls = cls;
	pm->has_body = !(original->common.fn_flags & ZEND_ACC_ABSTRACT);
	zend_hash_index_add_new_ptr(&cls->method_cache, (zend_ulong) (uintptr_t) original, pm);
	return pm;
}

/* Mirrors zend_duplicate_function(): share the original static method with
 * the generated class the way inheritance does. */
static zend_function *proxy_share_function(zend_function *func)
{
	if (func->type == ZEND_INTERNAL_FUNCTION) {
		zend_function *copy = zend_arena_alloc(&CG(arena), sizeof(zend_internal_function));
		memcpy(copy, func, sizeof(zend_internal_function));
		copy->common.fn_flags |= ZEND_ACC_ARENA_ALLOCATED;
		if (copy->common.function_name) {
			zend_string_addref(copy->common.function_name);
		}
		return copy;
	}
	if (func->op_array.refcount) {
		(*func->op_array.refcount)++;
	}
	if (func->op_array.function_name) {
		zend_string_addref(func->op_array.function_name);
	}
	return func;
}

zend_function *proxy_dynamic_trampoline(proxy_class *cls, zend_string *name)
{
	zend_internal_function *fn;

	if (EXPECTED(EG(trampoline).common.function_name == NULL)) {
		fn = &EG(trampoline).internal_function;
	} else {
		fn = ecalloc(1, sizeof(zend_function));
	}
	memset(fn, 0, sizeof(zend_internal_function));
	fn->type = ZEND_INTERNAL_FUNCTION;
	fn->fn_flags = ZEND_ACC_CALL_VIA_TRAMPOLINE | ZEND_ACC_PUBLIC | ZEND_ACC_VARIADIC;
	if (cls->has_call) {
		fn->fn_flags |= cls->type_ce->__call->common.fn_flags & ZEND_ACC_RETURN_REFERENCE;
	}
	fn->function_name = zend_string_copy(name);
	fn->scope = cls->ce;
	fn->arg_info = (zend_internal_arg_info *) &proxy_dynamic_arg_info[1];
	fn->handler = proxy_dynamic_method_handler;
	ZEND_MAP_PTR_INIT(fn->run_time_cache, NULL);
	fn->reserved[proxy_reserved_slot] = cls;
	return (zend_function *) fn;
}

/* ------------------------------------------------------------------------- */
/* Class assembly                                                            */
/* ------------------------------------------------------------------------- */

static void proxy_inherit_default_properties(zend_class_entry *ce, const zend_class_entry *parent)
{
	int count = parent->default_properties_count;
	if (!count) {
		return;
	}

	ce->default_properties_table = emalloc(sizeof(zval) * count);
	for (int index = count - 1; index >= 0; index--) {
		zval *src = &parent->default_properties_table[index];
		zval *dst = &ce->default_properties_table[index];

		if (parent->type != ce->type) {
			/* Internal defaults are not refcounted; user defaults are shared. */
			ZEND_ASSERT(!Z_REFCOUNTED_P(src));
			ZVAL_COPY_VALUE_PROP(dst, src);
		} else {
			ZVAL_COPY_PROP(dst, src);
		}
		if (Z_OPT_TYPE_P(dst) == IS_CONSTANT_AST) {
			ce->ce_flags &= ~ZEND_ACC_CONSTANTS_UPDATED;
			ce->ce_flags |= ZEND_ACC_HAS_AST_PROPERTIES;
		}
	}
	ce->default_properties_count = count;
}

static void proxy_inherit_property_info(zend_class_entry *ce, const zend_class_entry *type_ce)
{
	zend_string *key;
	zend_property_info *info;

	ZEND_HASH_MAP_FOREACH_STR_KEY_PTR(&type_ce->properties_info, key, info) {
		if (info->hooks) {
			ce->num_hooked_props++;
		}
		zend_hash_add_new_ptr(&ce->properties_info, key, info);
	} ZEND_HASH_FOREACH_END();
}

static void proxy_inherit_static_members(zend_class_entry *ce, const zend_class_entry *parent)
{
	int count = parent->default_static_members_count;
	if (!count) {
		return;
	}

	ce->default_static_members_table = emalloc(sizeof(zval) * count);
	for (int index = count - 1; index >= 0; index--) {
		zval *src = &parent->default_static_members_table[index];
		zval *dst = &ce->default_static_members_table[index];
		zval *storage = Z_TYPE_P(src) == IS_INDIRECT ? Z_INDIRECT_P(src) : src;

		/* Static properties keep sharing the original class's storage. */
		ZVAL_INDIRECT(dst, storage);
		if (Z_TYPE_P(storage) == IS_CONSTANT_AST) {
			ce->ce_flags |= ZEND_ACC_HAS_AST_STATICS;
		}
	}
	ce->default_static_members_count = count;
	/* Force zend_update_class_constants() to run before the first static
	 * member access so that the static members table gets initialized. */
	ce->ce_flags &= ~ZEND_ACC_CONSTANTS_UPDATED;
}

static void proxy_inherit_constants(zend_class_entry *ce, const zend_class_entry *type_ce)
{
	zend_string *key;
	zend_class_constant *c;

	ZEND_HASH_MAP_FOREACH_STR_KEY_PTR(&type_ce->constants_table, key, c) {
		if (ZEND_CLASS_CONST_FLAGS(c) & ZEND_ACC_PRIVATE) {
			continue;
		}
		if (Z_TYPE(c->value) == IS_CONSTANT_AST) {
			ce->ce_flags &= ~ZEND_ACC_CONSTANTS_UPDATED;
			ce->ce_flags |= ZEND_ACC_HAS_AST_CONSTANTS;
			if (type_ce->ce_flags & ZEND_ACC_IMMUTABLE) {
				zend_class_constant *copy = zend_arena_alloc(&CG(arena), sizeof(zend_class_constant));
				memcpy(copy, c, sizeof(zend_class_constant));
				Z_CONSTANT_FLAGS(copy->value) |= CONST_OWNED;
				c = copy;
			}
		}
		zend_hash_add_new_ptr(&ce->constants_table, key, c);
	} ZEND_HASH_FOREACH_END();
}

static void proxy_inherit_interfaces(zend_class_entry *ce, const zend_class_entry *type_ce, bool is_interface)
{
	uint32_t count = type_ce->num_interfaces + (is_interface ? 1 : 0);

	if (count) {
		ce->interfaces = emalloc(sizeof(zend_class_entry *) * count);
		uint32_t next = 0;
		if (is_interface) {
			ce->interfaces[next++] = (zend_class_entry *) type_ce;
		}
		for (uint32_t i = 0; i < type_ce->num_interfaces; i++) {
			ce->interfaces[next++] = type_ce->interfaces[i];
		}
		ce->num_interfaces = count;
	}
	ce->ce_flags |= ZEND_ACC_RESOLVED_INTERFACES;
}

static void proxy_inherit_methods(proxy_class *cls, zend_class_entry *ce, const zend_class_entry *type_ce)
{
	zend_string *key;
	zend_function *func;

	ZEND_HASH_MAP_FOREACH_STR_KEY_PTR(&type_ce->function_table, key, func) {
		if (func->common.fn_flags & ZEND_ACC_STATIC) {
			zend_hash_add_new_ptr(&ce->function_table, key, proxy_share_function(func));
		} else {
			proxy_method *pm = proxy_method_for_function(cls, func, true);
			zend_hash_add_new_ptr(&ce->function_table, key, &pm->fn);
		}
	} ZEND_HASH_FOREACH_END();

	/* Magic method pointers mirror an inheriting class. The destructor and
	 * __clone are deliberately left unset: the proxy's own lifetime must not
	 * run the proxied type's destructor, and cloning is unsupported. */
	ce->constructor = zend_hash_str_find_ptr(&ce->function_table, ZEND_STRL(ZEND_CONSTRUCTOR_FUNC_NAME));
	ce->destructor = NULL;
	ce->clone = NULL;
	ce->__get = zend_hash_str_find_ptr(&ce->function_table, ZEND_STRL(ZEND_GET_FUNC_NAME));
	ce->__set = zend_hash_str_find_ptr(&ce->function_table, ZEND_STRL(ZEND_SET_FUNC_NAME));
	ce->__unset = zend_hash_str_find_ptr(&ce->function_table, ZEND_STRL(ZEND_UNSET_FUNC_NAME));
	ce->__isset = zend_hash_str_find_ptr(&ce->function_table, ZEND_STRL(ZEND_ISSET_FUNC_NAME));
	ce->__call = zend_hash_str_find_ptr(&ce->function_table, ZEND_STRL(ZEND_CALL_FUNC_NAME));
	ce->__callstatic = zend_hash_str_find_ptr(&ce->function_table, ZEND_STRL(ZEND_CALLSTATIC_FUNC_NAME));
	ce->__tostring = zend_hash_str_find_ptr(&ce->function_table, ZEND_STRL(ZEND_TOSTRING_FUNC_NAME));
	ce->__debugInfo = zend_hash_str_find_ptr(&ce->function_table, ZEND_STRL(ZEND_DEBUGINFO_FUNC_NAME));
	ce->__serialize = NULL;
	ce->__unserialize = NULL;

	if (ce->__get || ce->__set || ce->__isset || ce->__unset) {
		ce->ce_flags |= ZEND_ACC_USE_GUARDS;
	}
	/* ext/json marks JsonSerializable implementations with USE_GUARDS (the
	 * encoder uses property guards for recursion detection). */
	for (uint32_t i = 0; i < ce->num_interfaces; i++) {
		if (zend_string_equals_literal(ce->interfaces[i]->name, "JsonSerializable")) {
			ce->ce_flags |= ZEND_ACC_USE_GUARDS;
			break;
		}
	}
	cls->has_call = ce->__call != NULL;
}

static void proxy_build_properties_info_table(zend_class_entry *ce, const zend_class_entry *type_ce)
{
	if (!ce->default_properties_count) {
		return;
	}
	size_t size = sizeof(zend_property_info *) * ce->default_properties_count;
	ce->properties_info_table = zend_arena_alloc(&CG(arena), size);
	if (type_ce->properties_info_table) {
		memcpy(ce->properties_info_table, type_ce->properties_info_table, size);
	} else {
		memset(ce->properties_info_table, 0, size);
	}
}

static zend_string *proxy_generate_class_name(const zend_class_entry *type_ce, zend_string **lcname_out)
{
	while (1) {
		zend_string *name = zend_strpprintf(0, "%sProxy_%x", ZSTR_VAL(type_ce->name), (unsigned) ++PROXY_G(class_counter));
		zend_string *lcname = zend_string_tolower(name);
		if (!zend_hash_exists(EG(class_table), lcname)) {
			*lcname_out = lcname;
			return name;
		}
		zend_string_release(lcname);
		zend_string_release(name);
	}
}

static proxy_class *proxy_class_create(zend_class_entry *type_ce)
{
	bool is_interface = (type_ce->ce_flags & ZEND_ACC_INTERFACE) != 0;
	zend_string *lcname;
	zend_string *name = proxy_generate_class_name(type_ce, &lcname);

	proxy_class *cls = zend_arena_calloc(&CG(arena), 1, sizeof(proxy_class));
	zend_class_entry *ce = &cls->ce_storage;
	ce->type = ZEND_USER_CLASS;
	ce->name = name;
	zend_initialize_class_data(ce, 1);
	ce->ce_flags |= ZEND_ACC_FINAL | ZEND_ACC_LINKED;
	ce->info.user.filename = ZSTR_EMPTY_ALLOC();
	ce->info.user.line_start = 0;
	ce->info.user.line_end = 0;

	cls->ce = ce;
	cls->type_ce = type_ce;
	zend_hash_init(&cls->method_cache, 0, NULL, NULL, 0);

	if (is_interface) {
		cls->parent_handlers = &std_object_handlers;
		cls->parent_create_object = NULL;
	} else {
		cls->parent_handlers = type_ce->default_object_handlers;
		cls->parent_create_object = type_ce->create_object;
	}
	cls->embedded = (cls->parent_create_object == NULL);
	proxy_class_init_handlers(cls);
	/* Every proxy is created by proxy_create_object(), which installs the proxy
	 * handlers on the object explicitly. The engine may also allocate objects
	 * of the class itself, past create_object (zend_objects_new() for lazy
	 * ghosts and lazy proxies made through reflection): with the standard
	 * handlers as the class default such an object is an ordinary object of
	 * the generated class from birth, whose methods refuse to run. Classes
	 * with internal ancestry cannot be made lazy; their allocator runs with
	 * the parent's handlers (see proxy_create_object()). */
	ce->default_object_handlers = cls->embedded ? &std_object_handlers : &cls->handlers.h;
	ce->create_object = proxy_create_object;

	if (is_interface) {
		ce->ce_flags |= type_ce->ce_flags & (ZEND_ACC_HAS_TYPE_HINTS | ZEND_ACC_HAS_READONLY_PROPS);
	} else {
		ce->parent = type_ce;
		ce->ce_flags |= ZEND_ACC_RESOLVED_PARENT;
		proxy_inherit_default_properties(ce, type_ce);
		proxy_inherit_static_members(ce, type_ce);
		ce->ce_flags |= type_ce->ce_flags & PROXY_MIRRORED_CLASS_FLAGS;
	}
	proxy_inherit_property_info(ce, type_ce);
	proxy_inherit_constants(ce, type_ce);
	proxy_inherit_interfaces(ce, type_ce, is_interface);
	proxy_inherit_methods(cls, ce, type_ce);
	proxy_build_properties_info_table(ce, type_ce);

	/* Every proxy iterates through its own iterator: Iterator/IteratorAggregate
	 * dispatch through the chain, everything else enumerates properties with
	 * the scope, hook and interception rules of a direct access. Letting the
	 * VM walk a properties table would expose slots that belong to the target
	 * (engine code maps IS_INDIRECT slots back to the iterated object). */
	ce->get_iterator = proxy_get_iterator;
	if (!instanceof_function(ce, zend_ce_countable) && !cls->parent_handlers->count_elements) {
		/* is_countable()/count() consult the handler's presence. */
		cls->handlers.h.count_elements = NULL;
	}
	/* json_encode() guards against recursion on the properties table of a
	 * class without hooks and on the object itself otherwise. The proxy's
	 * property views are computed per call (they go through the interceptor
	 * chain), so only the object guard detects a proxy that contains itself.
	 * The counter has no other effect on a class whose property infos belong
	 * to the proxied type: std_get_properties_for() is overridden, hook static
	 * variable cleanup matches prop_info->ce == ce, and the class is never
	 * persisted by opcache. */
	if (!ce->num_hooked_props) {
		ce->num_hooked_props = 1;
	}
	/* No serialize/unserialize callbacks: serialization is refused by the
	 * SERIALIZE property view, and unserialize() of an O: payload fails when
	 * it tries to instantiate the class (a C: payload has no unserializer). */

	zend_hash_add_new_ptr(EG(class_table), lcname, ce);
	zend_string_release(lcname);

	return cls;
}

proxy_class *proxy_class_get(zend_class_entry *type_ce)
{
	zend_ulong key = (zend_ulong) (uintptr_t) type_ce;
	proxy_class *cls = zend_hash_index_find_ptr(&PROXY_G(classes), key);
	if (cls) {
		return cls;
	}
	cls = proxy_class_create(type_ce);
	zend_hash_index_add_new_ptr(&PROXY_G(classes), key, cls);
	return cls;
}
