/*
 * ext/proxy - object handlers of proxy instances
 *
 * Every proxy instance carries a handler table owned by its generated class.
 * The handlers route method calls, property and dimension operations, casts,
 * counting and iteration through the interceptors, or delegate to the
 * wrapped target when no interceptor applies.
 */

#include "php_proxy.h"
#include "zend_gc.h"
#include "zend_weakrefs.h"
#include "zend_lazy_objects.h"

static void proxy_dtor_obj(zend_object *obj);

struct _proxy_raw_walk {
	zend_execute_data *frame; /* compared with live frames, never dereferenced */
	HashTable *table;
	uint32_t iterator;
	proxy_raw_walk *next;
};

static void proxy_free_raw_walks(proxy_object *po)
{
	while (po->raw_walks) {
		proxy_raw_walk *walk = po->raw_walks;
		po->raw_walks = walk->next;
		efree(walk);
	}
}

/* The object whose property slots hold the target's state: the real instance
 * behind an initialized lazy proxy, otherwise the target itself. With `init`
 * an uninitialized lazy target is initialized first, as PHP does before it
 * inspects a slot; NULL is returned when the initializer threw. */
zend_object *proxy_storage_object(proxy_object *po, bool init)
{
	zend_object *target = po->target;
	if (!target || !zend_object_is_lazy(target)) {
		return target;
	}
	if (!zend_lazy_object_initialized(target) && !init) {
		return NULL;
	}
	/* Initializes an uninitialized lazy object; for an initialized lazy proxy
	 * (the only initialized object that still counts as lazy) it returns the
	 * real instance without side effects. NULL when the initializer threw. */
	return zend_lazy_object_init(target);
}

/* ReflectionProperty::getRawValue()/setRawValue() run a hook trampoline whose
 * frame names the property and the object, so that the engine bypasses the
 * hook ("we are inside this property's hook"). Recognize that frame for the
 * proxy: the raw operation must reach the target's storage, without hooks or
 * interceptors. Returns the frame to retarget, or NULL. */
static zend_execute_data *proxy_raw_access_frame(zend_object *obj, zend_string *name)
{
	zend_execute_data *ex = EG(current_execute_data);
	if (ex && ex->func && ex->func->common.prop_info && ex->func->common.prop_info->hooks
			&& Z_TYPE(ex->This) == IS_OBJECT && Z_OBJ(ex->This) == obj) {
		const char *unmangled = zend_get_unmangled_property_name(ex->func->common.prop_info->name);
		if (zend_string_equals_cstr(name, unmangled, strlen(unmangled))) {
			return ex;
		}
	}
	return NULL;
}

/* ------------------------------------------------------------------------- */
/* Instance state                                                            */
/* ------------------------------------------------------------------------- */

proxy_object *proxy_object_from_obj(zend_object *obj)
{
	if (UNEXPECTED(obj->handlers == &std_object_handlers)) {
		return NULL;
	}
	proxy_class *cls = proxy_class_from_handlers(obj->handlers);
	if (cls->embedded) {
		/* only proxy_create_object() installs the proxy handlers */
		return (proxy_object *) ((char *) obj - XtOffsetOf(proxy_object, std));
	}
	if (!PROXY_G(tables_initialized)) {
		return NULL;
	}
	return zend_hash_index_find_ptr(&PROXY_G(objects), obj->handle);
}

bool proxy_refuse_lazy(zend_object *obj)
{
	if (!zend_object_is_lazy(obj)) {
		return false;
	}
	if (!zend_lazy_object_initialized(obj)) {
		/* drops the initializer (and any cycle through it) and restores the
		 * property defaults; the proxy keeps working on its target */
		zend_lazy_object_mark_as_initialized(obj);
	}
	proxy_throw(proxy_ce_UnsupportedOperation, "Proxy object of class %s cannot be used as a lazy object", ZSTR_VAL(obj->ce->name));
	return true;
}

zend_class_entry *proxy_current_scope(void)
{
	if (EG(fake_scope)) {
		return (zend_class_entry *) EG(fake_scope);
	}
	return zend_get_executed_scope();
}

static zend_always_inline bool proxy_object_usable(const proxy_object *po)
{
	return po && po->initialized;
}

static ZEND_COLD void proxy_throw_bare(zend_object *obj)
{
	/* the refused instantiation that produced this object may already be
	 * the pending exception (unserialize() keeps writing properties) */
	if (!EG(exception)) {
		proxy_throw(proxy_ce_UnsupportedOperation, "Proxy object of class %s was not created through Proxy\\Proxy::mock() or Proxy\\Proxy::wrap()", ZSTR_VAL(obj->ce->name));
	}
}

static void proxy_release_raw_snapshot(zend_object *obj);

/* Resolves the proxy state for a handler call. Returns false when the call
 * must not run the proxy logic: `std` is set when the object is a plain
 * object of the generated class (delegate to the standard handler), otherwise
 * an exception has been thrown. */
static bool proxy_handler_state_ex(zend_object *obj, proxy_object **po_out, bool *std, bool release_raw)
{
	proxy_object *po = proxy_object_from_obj(obj);
	*po_out = po;
	*std = false;
	if (UNEXPECTED(!po)) {
		if (obj->handlers == &std_object_handlers) {
			*std = true;
		} else {
			proxy_throw_bare(obj);
		}
		return false;
	}
	if (UNEXPECTED(!po->initialized)) {
		proxy_throw_bare(obj);
		return false;
	}
	if (UNEXPECTED(zend_object_is_lazy(obj)) && proxy_refuse_lazy(obj)) {
		return false;
	}
	if (release_raw && UNEXPECTED(obj->properties) && po->target) {
		proxy_release_raw_snapshot(obj);
		if (EG(exception)) {
			return false;
		}
	}
	return true;
}

static zend_always_inline bool proxy_handler_state(zend_object *obj, proxy_object **po_out, bool *std)
{
	return proxy_handler_state_ex(obj, po_out, std, true);
}

zend_object *proxy_create_object(zend_class_entry *ce)
{
	proxy_class *cls = proxy_class_from_ce(ce);

	if (UNEXPECTED(!PROXY_G(creating))) {
		/* new, ReflectionClass::newInstance*(), newLazyGhost()/newLazyProxy()
		 * of a generated class: an instance without interceptor configuration
		 * has no behaviour. The engine needs an object back; it is released
		 * together with the exception. */
		proxy_throw(proxy_ce_UnsupportedOperation, "Proxy class %s cannot be instantiated directly; use Proxy\\Proxy::mock() or Proxy\\Proxy::wrap()", ZSTR_VAL(ce->name));
	}

	if (cls->embedded) {
		proxy_object *po = zend_object_alloc(sizeof(proxy_object), ce);
		memset(po, 0, XtOffsetOf(proxy_object, std));
		zend_object_std_init(&po->std, ce);
		for (int i = 0; i < ce->default_properties_count; i++) {
			zval *slot = &po->std.properties_table[i];
			ZVAL_UNDEF(slot);
			Z_PROP_FLAG_P(slot) = IS_PROP_UNINIT;
		}
		po->std.handlers = &cls->handlers.h;
		po->cls = cls;
		po->obj = &po->std;
		ZVAL_UNDEF(&po->method_interceptor);
		ZVAL_UNDEF(&po->property_interceptor);
		return &po->std;
	}

	/* Internal ancestry: let the internal class allocate its own layout so
	 * that internal functions receiving the proxy see a valid (uninitialized)
	 * object, and keep the proxy state out of line. The allocator may touch
	 * properties of the new object (exceptions do), so it runs with the
	 * internal class' handlers; ours are installed afterwards. */
	ce->default_object_handlers = cls->parent_handlers;
	zend_object *obj = cls->parent_create_object(ce);
	ce->default_object_handlers = &cls->handlers.h;
	obj->handlers = &cls->handlers.h;
	proxy_object *po = ecalloc(1, sizeof(proxy_object));
	po->cls = cls;
	po->obj = obj;
	ZVAL_UNDEF(&po->method_interceptor);
	ZVAL_UNDEF(&po->property_interceptor);
	zend_hash_index_update_ptr(&PROXY_G(objects), obj->handle, po);
	return obj;
}

void proxy_object_configure(proxy_object *po, zend_object *target, zval *method_interceptor, zval *property_interceptor)
{
	if (target) {
		GC_ADDREF(target);
		po->target = target;
	}
	if (method_interceptor) {
		ZVAL_COPY(&po->method_interceptor, method_interceptor);
	}
	if (property_interceptor) {
		ZVAL_COPY(&po->property_interceptor, property_interceptor);
	}
	po->initialized = true;
}

static void proxy_object_release_state(proxy_object *po)
{
	proxy_free_raw_walks(po);
	if (po->target) {
		zend_object *target = po->target;
		po->target = NULL;
		OBJ_RELEASE(target);
	}
	zval_ptr_dtor(&po->method_interceptor);
	ZVAL_UNDEF(&po->method_interceptor);
	zval_ptr_dtor(&po->property_interceptor);
	ZVAL_UNDEF(&po->property_interceptor);
}

void proxy_free_obj(zend_object *obj)
{
	proxy_class *cls = proxy_class_from_handlers(obj->handlers);
	proxy_object *po = proxy_object_from_obj(obj);

	/* Releasing the target and the interceptors can run user destructors.
	 * Clear weak references first, as zend_object_std_dtor() does before it
	 * destroys properties: WeakReference::get()/WeakMap must not hand out an
	 * object that is being freed. */
	if (UNEXPECTED(GC_FLAGS(obj) & IS_OBJ_WEAKLY_REFERENCED)) {
		zend_weakrefs_notify(obj);
		GC_DEL_FLAGS(obj, IS_OBJ_WEAKLY_REFERENCED);
	}
	if (po) {
		proxy_object_release_state(po);
	}
	if (cls->embedded) {
		zend_object_std_dtor(obj);
	} else {
		if (po) {
			zend_hash_index_del(&PROXY_G(objects), obj->handle);
			efree(po);
		}
		cls->parent_handlers->free_obj(obj);
	}
}

static void proxy_dtor_obj(zend_object *obj)
{
	/* The proxy has no destructor semantics of its own; the target's
	 * destructor runs when the target itself is released.
	 *
	 * ReflectionClass::resetAsLazyGhost()/resetAsLazyProxy() call this
	 * handler before turning the object into a lazy object and give up when
	 * it throws: a proxy that is lazy cannot work (engine code asserts that
	 * lazy objects use the standard property handlers). */
	zend_execute_data *ex = EG(current_execute_data);
	if (ex && ex->func && ex->func->type == ZEND_INTERNAL_FUNCTION && ex->func->common.scope
			&& zend_string_equals_literal(ex->func->common.scope->name, "ReflectionClass")
			&& ZSTR_LEN(ex->func->common.function_name) > sizeof("resetAsLazy") - 1
			&& strncmp(ZSTR_VAL(ex->func->common.function_name), "resetAsLazy", sizeof("resetAsLazy") - 1) == 0
			&& ZEND_CALL_NUM_ARGS(ex) >= 1 && Z_TYPE_P(ZEND_CALL_ARG(ex, 1)) == IS_OBJECT
			&& Z_OBJ_P(ZEND_CALL_ARG(ex, 1)) == obj) {
		/* the reset marked the destructor as called; a later attempt must
		 * reach this refusal again */
		GC_DEL_FLAGS(obj, IS_OBJ_DESTRUCTOR_CALLED);
		proxy_throw(proxy_ce_UnsupportedOperation, "Proxy object of class %s cannot be made lazy", ZSTR_VAL(obj->ce->name));
	}
}

static HashTable *proxy_get_gc(zend_object *obj, zval **table, int *n)
{
	proxy_class *cls = proxy_class_from_handlers(obj->handlers);
	proxy_object *po = proxy_object_from_obj(obj);
	if (UNEXPECTED(!po && obj->handlers == &std_object_handlers)) {
		/* plain object of the generated class (lazy-object edges included) */
		return zend_std_get_gc(obj, table, n);
	}
	zend_get_gc_buffer *buf = zend_get_gc_buffer_create();
	HashTable *ret;

	/* A parent handler may reset the same shared Zend GC buffer. Collect its
	 * edges first, before adding any extension-owned references. */
	if (!cls->embedded && cls->parent_handlers->get_gc && cls->parent_handlers->get_gc != zend_std_get_gc) {
		zval *ptable = NULL;
		int pn = 0;
		ret = cls->parent_handlers->get_gc(obj, &ptable, &pn);
		if (ptable == buf->start) {
			/* The parent already populated our buffer. Appending those values
			 * again would report duplicate edges (and may invalidate ptable
			 * when growing the buffer). */
			if (pn) {
				buf->cur = buf->start + pn;
			}
		} else {
			buf = zend_get_gc_buffer_create();
			for (int i = 0; i < pn; i++) {
				zend_get_gc_buffer_add_zval(buf, &ptable[i]);
			}
		}
	} else if (obj->properties) {
		/* Like zend_std_get_gc(): the table's IS_INDIRECT entries make the
		 * collector visit the slots; listing them again would count every
		 * reference twice. */
		ret = obj->properties;
	} else {
		zval *slot = obj->properties_table;
		for (int i = 0; i < obj->ce->default_properties_count; i++) {
			zend_get_gc_buffer_add_zval(buf, &slot[i]);
		}
		ret = NULL;
	}

	if (po) {
		if (po->target) {
			zend_get_gc_buffer_add_obj(buf, po->target);
		}
		zend_get_gc_buffer_add_zval(buf, &po->method_interceptor);
		zend_get_gc_buffer_add_zval(buf, &po->property_interceptor);
	}

	zend_get_gc_buffer_use(buf, table, n);
	return ret;
}

static zend_object *proxy_clone_obj(zend_object *obj)
{
	proxy_throw(proxy_ce_UnsupportedOperation, "Proxy objects of class %s cannot be cloned", ZSTR_VAL(obj->ce->name));
	/* The ZEND_CLONE opcode stores the result unconditionally and frees it
	 * while unwinding, so a valid object must be returned alongside the
	 * exception: hand back the proxy itself with an extra reference. */
	GC_ADDREF(obj);
	return obj;
}

#if PHP_VERSION_ID >= 80500
static zend_object *proxy_clone_obj_with(zend_object *obj, const zend_class_entry *scope, const HashTable *properties)
{
	return proxy_clone_obj(obj);
}
#endif

static zend_function *proxy_get_constructor(zend_object *obj)
{
	/* proxy_create_object() already refused `new`; avoid chaining a second
	 * exception onto the first one. */
	if (!EG(exception)) {
		proxy_throw(proxy_ce_UnsupportedOperation, "Proxy class %s cannot be instantiated directly; use Proxy\\Proxy::mock() or Proxy\\Proxy::wrap()", ZSTR_VAL(obj->ce->name));
	}
	return NULL;
}

/* ------------------------------------------------------------------------- */
/* Method lookup                                                             */
/* ------------------------------------------------------------------------- */

static zend_function *proxy_get_method(zend_object **obj_ptr, zend_string *method_name, const zval *key)
{
	/* Let Zend choose the member and enforce scope/visibility, including magic
	 * fallback. Resolved instance methods all use the same trampoline cache. */
	zend_function *fbc = zend_std_get_method(obj_ptr, method_name, key);
	if (!fbc) {
		return NULL;
	}
	proxy_class *cls = proxy_class_from_handlers((*obj_ptr)->handlers);
	if (fbc->common.fn_flags & ZEND_ACC_CALL_VIA_TRAMPOLINE) {
		zend_string_release_ex(fbc->common.function_name, 0);
		zend_free_trampoline(fbc);
		return proxy_dynamic_trampoline(cls, method_name);
	}
	if ((fbc->common.fn_flags & ZEND_ACC_STATIC) || proxy_function_is_trampoline(fbc)) {
		return fbc;
	}
	/* Zend selected an original function (a private ancestor method through
	 * the private-shadow rule): dispatch it through its trampoline. */
	return (zend_function *) proxy_method_for_function(cls, fbc, false);
}

static zend_result proxy_get_closure(zend_object *obj, zend_class_entry **ce_ptr, zend_function **fptr_ptr, zend_object **obj_ptr, bool check_only)
{
	if (UNEXPECTED(!proxy_is_proxy(obj))) {
		return zend_std_get_closure(obj, ce_ptr, fptr_ptr, obj_ptr, check_only);
	}
	proxy_class *cls = proxy_class_from_handlers(obj->handlers);
	zend_function *fn = zend_hash_str_find_ptr(&cls->ce->function_table, ZEND_STRL(ZEND_INVOKE_FUNC_NAME));

	if (!fn || !proxy_function_is_trampoline(fn)) {
		return FAILURE;
	}
	*fptr_ptr = fn;
	*ce_ptr = obj->ce;
	*obj_ptr = obj;
	return SUCCESS;
}

/* Property metadata lookup must use the access site's scope, not the scope of
 * an interceptor that called proceed(). Keep the fake scope local to Zend. */
static zend_property_info *proxy_property_info_in_scope(
		zend_class_entry *ce, zend_string *name, zend_class_entry *scope)
{
	const zend_class_entry *old_scope = EG(fake_scope);
	EG(fake_scope) = scope;
	zend_property_info *info = zend_get_property_info(ce, name, /* silent */ true);
	EG(fake_scope) = (zend_class_entry *) old_scope;
	return info;
}

/* When a handler hands the engine a pointer into the target's storage, the
 * VM expects the call-site cache to describe the property (typed compound
 * assignments and by-reference fetches consult cache_slot[2]). Publish the
 * target's property info but never a class/offset pair, so that the fast
 * paths (which would index the proxy's own slots) stay disabled. */
static zend_property_info *proxy_publish_prop_info(proxy_object *po, zend_string *name, zend_class_entry *scope, void **cache_slot)
{
	zend_property_info *info = proxy_property_info_in_scope(po->target->ce, name, scope);
	if (info == ZEND_WRONG_PROPERTY_INFO || !info || (info->flags & ZEND_ACC_STATIC) || !ZEND_TYPE_IS_SET(info->type)) {
		info = NULL;
	}
	if (cache_slot) {
		cache_slot[0] = NULL;
		cache_slot[1] = NULL;
		cache_slot[2] = info;
	}
	return info;
}

/* ------------------------------------------------------------------------- */
/* Properties                                                                */
/* ------------------------------------------------------------------------- */

static bool proxy_initialize_detached_slot(zval *slot, const zend_property_info *info)
{
	/* Normally the VM sees the property slot and applies these flags itself.
	 * When releasing the last proxy reference forces us to return an owned rv,
	 * initialize the slot before moving it. Zend's property handlers likewise
	 * inspect the current opcode for fetch context; no opcode is modified. */
	zend_execute_data *execute_data = EG(current_execute_data);
	uint32_t flags = 0;
	if (execute_data && execute_data->func && ZEND_USER_CODE(execute_data->func->type)
			&& execute_data->opline
			&& (execute_data->opline->opcode == ZEND_FETCH_OBJ_W
				|| execute_data->opline->opcode == ZEND_FETCH_OBJ_FUNC_ARG)) {
		flags = execute_data->opline->extended_value & ZEND_FETCH_OBJ_FLAGS;
	}
	if (flags == ZEND_FETCH_DIM_WRITE) {
		if (ZEND_TYPE_FULL_MASK(info->type) & MAY_BE_ARRAY) {
			array_init(slot);
			return true;
		}
		zend_string *type = zend_type_to_string(info->type);
		zend_type_error("Cannot auto-initialize an array inside property %s::$%s of type %s",
			ZSTR_VAL(info->ce->name), zend_get_unmangled_property_name(info->name), ZSTR_VAL(type));
		zend_string_release(type);
		return false;
	}
	if (flags != ZEND_FETCH_REF) {
		/* Nested object writes have no reference/array fetch flag. Their
		 * subsequent opcode reports the null-object error without initializing
		 * the property, including when its declared type allows null. */
		return true;
	}
	if (ZEND_TYPE_ALLOW_NULL(info->type)) {
		ZVAL_NULL(slot);
		return true;
	}
	zend_throw_error(NULL, "Cannot access uninitialized non-nullable property %s::$%s by reference",
		ZSTR_VAL(info->ce->name), zend_get_unmangled_property_name(info->name));
	return false;
}

static zval *proxy_finish_read(zend_object *obj, zend_string *name, int type,
		zend_class_entry *scope, void **cache_slot, zval *rv, zval *ret)
{
	bool writable = type == BP_VAR_W || type == BP_VAR_RW || type == BP_VAR_UNSET;
	bool read_only = type == BP_VAR_R || type == BP_VAR_IS;

	/* A callback can remove the last external reference to the proxy. In
	 * that case its target may disappear as soon as we release our guard.
	 * Move borrowed storage into the VM-owned result before that happens.
	 * Writable fetches need a reference so an independently owned target
	 * still receives the modification. */
	if (ret != rv && ret != &EG(uninitialized_zval) && !Z_ISERROR_P(ret)
			&& GC_REFCOUNT(obj) == 1) {
		if (writable && !Z_ISREF_P(ret)) {
			proxy_object *po = proxy_object_from_obj(obj);
			zend_property_info *info = proxy_publish_prop_info(po, name, scope, cache_slot);
			if (Z_TYPE_P(ret) == IS_UNDEF) {
				/* An uninitialized slot must never be turned into a reference
				 * to UNDEF. unset($p->x[k]) has nothing to modify; a
				 * read-modify-write fetch reports PHP's initialization error;
				 * a plain writable fetch applies PHP's initialization rules. */
				if (type == BP_VAR_UNSET) {
					OBJ_RELEASE(obj);
					return &EG(uninitialized_zval);
				}
				if (type == BP_VAR_RW) {
					if (info) {
						zend_throw_error(NULL, "Typed property %s::$%s must not be accessed before initialization",
							ZSTR_VAL(info->ce->name), zend_get_unmangled_property_name(info->name));
					}
					OBJ_RELEASE(obj);
					return &EG(uninitialized_zval);
				}
				if (!info) {
					ZVAL_NULL(ret);
				} else {
					if (!proxy_initialize_detached_slot(ret, info)) {
						OBJ_RELEASE(obj);
						return &EG(uninitialized_zval);
					}
					if (Z_TYPE_P(ret) == IS_UNDEF) {
						/* There is no storage to retain for a nested object write.
						 * Let the VM reject the owned uninitialized result itself. */
						ZVAL_UNDEF(rv);
						OBJ_RELEASE(obj);
						return rv;
					}
				}
			}
			ZVAL_MAKE_REF(ret);
			if (info) {
				ZEND_REF_ADD_TYPE_SOURCE(Z_REF_P(ret), info);
			}
		}
		ZVAL_COPY(rv, ret);
		ret = rv;
	}
	if (ret != rv && read_only && Z_ISREF_P(ret) && Z_REFCOUNT_P(ret) == 1) {
		/* a reference nobody else holds is a value (get_object_vars() and
		 * similar consumers must not alias the target's slot through it) */
		ret = Z_REFVAL_P(ret);
	}
	if (ret == rv && read_only && Z_ISREF_P(rv)) {
		/* Read-only fetches consume values. Normalize our owned result before
		 * the VM's debug property-type check dereferences its pointer, otherwise
		 * that check's subsequent copy can overwrite the reference container. */
		zend_unwrap_reference(rv);
	}
	OBJ_RELEASE(obj);
	return ret;
}

/* proceed() exposes a writable target slot as a reference so that callbacks
 * can return it by reference. Once nobody else holds that reference, turn the
 * slot back into a plain value: the target's storage must look exactly as PHP
 * leaves it (the VM treats reference containers differently, e.g. it skips
 * the false-to-array deprecation for them, and makes its own references). */
static void proxy_unref_target_slot(proxy_object *po, zval *ptr)
{
	if (!Z_ISREF_P(ptr) || Z_REFCOUNT_P(ptr) != 1) {
		return;
	}
	zend_reference *ref = Z_REF_P(ptr);
	if (ZEND_REF_HAS_TYPE_SOURCES(ref)) {
		zend_object *storage = proxy_storage_object(po, false);
		if (!storage || ptr < storage->properties_table
				|| ptr >= storage->properties_table + storage->ce->default_properties_count) {
			return;
		}
		zend_property_info *info = zend_get_typed_property_info_for_slot(storage, ptr);
		if (!info) {
			return;
		}
		ZEND_REF_DEL_TYPE_SOURCE(ref, info);
		if (ZEND_REF_HAS_TYPE_SOURCES(ref)) {
			ZEND_REF_ADD_TYPE_SOURCE(ref, info);
			return;
		}
	}
	ZVAL_UNREF(ptr);
}

/* Inspect existing ordinary storage without resolving the property again.
 * A handler lookup could initialize a lazy target or recreate a dynamic
 * property after middleware already completed the requested operation. */
static zval *proxy_existing_property_slot(proxy_object *po, zend_string *name, zend_class_entry *scope)
{
	zend_object *storage = proxy_storage_object(po, false);
	if (!storage) {
		return NULL;
	}
	zend_property_info *info = proxy_property_info_in_scope(storage->ce, name, scope);
	if (info == ZEND_WRONG_PROPERTY_INFO) {
		return NULL;
	}
	if (info) {
		return info->hooks || (info->flags & (ZEND_ACC_STATIC | ZEND_ACC_VIRTUAL))
			? NULL : OBJ_PROP(storage, info->offset);
	}
	return storage->properties ? zend_hash_find(storage->properties, name) : NULL;
}

static zval *proxy_read_property(zend_object *obj, zend_string *name, int type, void **cache_slot, zval *rv)
{
	proxy_object *po;
	bool std;
	zend_class_entry *scope = proxy_current_scope();

	GC_ADDREF(obj);
	if (UNEXPECTED(!proxy_handler_state(obj, &po, &std))) {
		zval *ret = std ? zend_std_read_property(obj, name, type, cache_slot, rv) : &EG(uninitialized_zval);
		OBJ_RELEASE(obj);
		return ret;
	}

	zend_execute_data *raw = po->target ? proxy_raw_access_frame(obj, name) : NULL;
	if (po->target && (raw || !proxy_has_property_interceptor(po))) {
		const zend_class_entry *old_scope = EG(fake_scope);
		EG(fake_scope) = (zend_class_entry *) scope;
		if (raw) {
			/* raw access requested by reflection: let the target's handler
			 * see itself as the object whose hook is running */
			Z_OBJ(raw->This) = po->target;
		}
		zval *ret = po->target->handlers->read_property(po->target, name, type, NULL, rv);
		if (raw) {
			Z_OBJ(raw->This) = obj;
		}
		EG(fake_scope) = (zend_class_entry *) old_scope;
		return proxy_finish_read(obj, name, type, scope, cache_slot, rv, ret);
	}

	zval result;
	uint32_t flags = 0;
	if (proxy_dispatch_property_get(po, obj, name, type, scope, &result, &flags) == FAILURE) {
		if ((flags & PROXY_GET_RV_ERROR) && type == BP_VAR_W) {
			/* A get hook produced a value that cannot be modified: PHP's
			 * handler throws but still returns that value, and the VM treats
			 * it like any value (`$p->hooked = &$v` then reports "Cannot
			 * assign by reference to overloaded object" with the hook's error
			 * as previous). Return a value as well, so the VM does the same. */
			ZVAL_NULL(rv);
			OBJ_RELEASE(obj);
			return rv;
		}
		OBJ_RELEASE(obj);
		return &EG(uninitialized_zval);
	}

	if (type == BP_VAR_W || type == BP_VAR_RW || type == BP_VAR_UNSET) {
		/* Only native reference identity can forward target storage. Ordinary
		 * by-value results remain values even if they equal the target's value. */
		if ((flags & PROXY_GET_UNSET_SLOT) && type == BP_VAR_UNSET) {
			zval_ptr_dtor(&result);
			OBJ_RELEASE(obj);
			return &EG(uninitialized_zval);
		}
		if (flags & PROXY_GET_ORIGINAL_RAN) {
			zval *ptr = proxy_existing_property_slot(po, name, scope);
			if (ptr) {
				bool forwarded = Z_ISREF(result) && Z_ISREF_P(ptr) && Z_REF(result) == Z_REF_P(ptr);
				if ((flags & PROXY_GET_UNSET_SLOT) && Z_TYPE_P(ptr) == IS_UNDEF) {
					/* The dispatcher recognized the exact temporary reference
					 * representing this original uninitialized fetch. */
					forwarded = true;
				}
				if (forwarded) {
					zval_ptr_dtor(&result);
					proxy_unref_target_slot(po, ptr);
					proxy_publish_prop_info(po, name, scope, cache_slot);
					return proxy_finish_read(obj, name, type, scope, cache_slot, rv, ptr);
				}
				proxy_unref_target_slot(po, ptr);
			}
		}
		ZVAL_COPY_VALUE(rv, &result);
		if (!Z_ISREF_P(rv) && Z_TYPE_P(rv) != IS_OBJECT && !(flags & PROXY_GET_TARGET_NOTICE)) {
			/* A value where the VM needs storage follows the engine's
			 * overloaded-property semantics. When the target's __get() produced
			 * that value, zend_std_read_property() has already raised the notice. */
			zend_error(E_NOTICE, "Indirect modification of overloaded property %s::$%s has no effect", ZSTR_VAL(obj->ce->name), ZSTR_VAL(name));
		}
		return proxy_finish_read(obj, name, type, scope, cache_slot, rv, rv);
	}

	ZVAL_COPY_VALUE(rv, &result);
	return proxy_finish_read(obj, name, type, scope, cache_slot, rv, rv);
}

/* ++/-- on a property with interceptors runs the VM's overloaded path (read,
 * modify, write back). An integer that left its range arrives here as the
 * float 2^63 (or -2^63); a direct typed slot would have reported the overflow
 * instead of a failed float-to-int assignment. Returns true after throwing
 * that error. Any other float (a numeric string incremented past its integer
 * form, an interceptor result) keeps the ordinary assignment semantics. */
static bool proxy_incdec_overflow(proxy_object *po, zend_string *name, zend_class_entry *scope, zval *value)
{
	zend_execute_data *execute_data = EG(current_execute_data);
	if (!execute_data || !execute_data->func || !ZEND_USER_CODE(execute_data->func->type) || !execute_data->opline) {
		return false;
	}
	uint8_t opcode = execute_data->opline->opcode;
	if (opcode != ZEND_PRE_INC_OBJ && opcode != ZEND_PRE_DEC_OBJ
			&& opcode != ZEND_POST_INC_OBJ && opcode != ZEND_POST_DEC_OBJ) {
		return false;
	}
	bool inc = opcode == ZEND_PRE_INC_OBJ || opcode == ZEND_POST_INC_OBJ;
	if (Z_DVAL_P(value) != (inc ? (double) ZEND_LONG_MAX + 1.0 : (double) ZEND_LONG_MIN - 1.0)) {
		return false;
	}
	zend_property_info *info = proxy_property_info_in_scope(proxy_type_of(po), name, scope);
	if (!info || info == ZEND_WRONG_PROPERTY_INFO || (info->flags & ZEND_ACC_STATIC) || !ZEND_TYPE_IS_SET(info->type)) {
		return false;
	}
	uint32_t mask = ZEND_TYPE_FULL_MASK(info->type);
	if (!(mask & MAY_BE_LONG) || (mask & MAY_BE_DOUBLE) || info->hooks || !po->target) {
		/* a set hook applies its own argument contract; a mock has no slot to compare */
		return false;
	}
	/* PHP reports the overflow only when the slot held an integer, and only
	 * after the readonly and set-visibility checks passed. */
	zend_object *storage = proxy_storage_object(po, false);
	if (!storage) {
		return false;
	}
	zval *slot = OBJ_PROP(storage, info->offset);
	ZVAL_DEREF(slot);
	if (Z_TYPE_P(slot) != IS_LONG) {
		return false;
	}
	if ((info->flags & ZEND_ACC_READONLY) && !(Z_PROP_FLAG_P(OBJ_PROP(storage, info->offset)) & IS_PROP_REINITABLE)) {
		return false;
	}
	if (info->flags & ZEND_ACC_PPP_SET_MASK) {
		const zend_class_entry *old_scope = EG(fake_scope);
		EG(fake_scope) = (zend_class_entry *) scope;
		bool allowed = zend_asymmetric_property_has_set_access(info);
		EG(fake_scope) = (zend_class_entry *) old_scope;
		if (!allowed) {
			return false;
		}
	}
	zend_string *type_str = zend_type_to_string(info->type);
	zend_type_error("Cannot %s property %s::$%s of type %s past its %s value",
		inc ? "increment" : "decrement", ZSTR_VAL(info->ce->name),
		zend_get_unmangled_property_name(info->name), ZSTR_VAL(type_str), inc ? "maximal" : "minimal");
	zend_string_release(type_str);
	return true;
}

static zval *proxy_write_property(zend_object *obj, zend_string *name, zval *value, void **cache_slot)
{
	proxy_object *po;
	bool std;
	zend_class_entry *scope = proxy_current_scope();

	GC_ADDREF(obj);
	if (UNEXPECTED(!proxy_handler_state(obj, &po, &std))) {
		zval *ret = std ? zend_std_write_property(obj, name, value, cache_slot) : value;
		OBJ_RELEASE(obj);
		return ret;
	}

	zend_execute_data *raw = po->target ? proxy_raw_access_frame(obj, name) : NULL;
	if (po->target && (raw || !proxy_has_property_interceptor(po))) {
		const zend_class_entry *old_scope = EG(fake_scope);
		EG(fake_scope) = (zend_class_entry *) scope;
		if (raw) {
			Z_OBJ(raw->This) = po->target;
		}
		zval *ret = po->target->handlers->write_property(po->target, name, value, NULL);
		if (raw) {
			Z_OBJ(raw->This) = obj;
		}
		EG(fake_scope) = (zend_class_entry *) old_scope;
		OBJ_RELEASE(obj);
		return ret;
	}

	if (UNEXPECTED(Z_TYPE_P(value) == IS_DOUBLE) && proxy_incdec_overflow(po, name, scope, value)) {
		OBJ_RELEASE(obj);
		return value;
	}
	proxy_dispatch_property(po, obj, PROXY_PROP_SET, name, 0, value, scope, NULL);
	OBJ_RELEASE(obj);
	return value;
}

static int proxy_has_property(zend_object *obj, zend_string *name, int has_set_exists, void **cache_slot)
{
	proxy_object *po;
	bool std;
	zend_class_entry *scope = proxy_current_scope();

	GC_ADDREF(obj);
	if (UNEXPECTED(!proxy_handler_state(obj, &po, &std))) {
		int ret = std ? zend_std_has_property(obj, name, has_set_exists, cache_slot) : 0;
		OBJ_RELEASE(obj);
		return ret;
	}

	if (!proxy_has_property_interceptor(po) && po->target) {
		const zend_class_entry *old_scope = EG(fake_scope);
		EG(fake_scope) = (zend_class_entry *) scope;
		int ret = po->target->handlers->has_property(po->target, name, has_set_exists, NULL);
		EG(fake_scope) = (zend_class_entry *) old_scope;
		OBJ_RELEASE(obj);
		return ret;
	}

	zval result;
	if (proxy_dispatch_property(po, obj, PROXY_PROP_ISSET, name, has_set_exists, NULL, scope, &result) == FAILURE) {
		OBJ_RELEASE(obj);
		return 0;
	}
	OBJ_RELEASE(obj);
	return Z_TYPE(result) == IS_TRUE;
}

static void proxy_unset_property(zend_object *obj, zend_string *name, void **cache_slot)
{
	proxy_object *po;
	bool std;
	zend_class_entry *scope = proxy_current_scope();

	GC_ADDREF(obj);
	if (UNEXPECTED(!proxy_handler_state(obj, &po, &std))) {
		if (std) {
			zend_std_unset_property(obj, name, cache_slot);
		}
		OBJ_RELEASE(obj);
		return;
	}

	if (!proxy_has_property_interceptor(po) && po->target) {
		const zend_class_entry *old_scope = EG(fake_scope);
		EG(fake_scope) = (zend_class_entry *) scope;
		po->target->handlers->unset_property(po->target, name, NULL);
		EG(fake_scope) = (zend_class_entry *) old_scope;
		OBJ_RELEASE(obj);
		return;
	}

	proxy_dispatch_property(po, obj, PROXY_PROP_UNSET, name, 0, NULL, scope, NULL);
	OBJ_RELEASE(obj);
}

static zval *proxy_get_property_ptr_ptr(zend_object *obj, zend_string *name, int type, void **cache_slot)
{
	proxy_object *po = proxy_object_from_obj(obj);

	if (UNEXPECTED(!po && obj->handlers == &std_object_handlers)) {
		return zend_std_get_property_ptr_ptr(obj, name, type, cache_slot);
	}
	/* Without a target, or when an interceptor applies, fall back to the
	 * read/write pair so that the interceptor observes the access
	 * (and reports a bare or lazy object). */
	if (!proxy_object_usable(po) || !po->target || zend_object_is_lazy(obj) || proxy_has_property_interceptor(po)) {
		return NULL;
	}

	zend_class_entry *scope = proxy_current_scope();
	const zend_class_entry *old_scope = EG(fake_scope);
	GC_ADDREF(obj);
	EG(fake_scope) = (zend_class_entry *) scope;
	zval *ptr = po->target->handlers->get_property_ptr_ptr(po->target, name, type, NULL);
	EG(fake_scope) = (zend_class_entry *) old_scope;
	if (UNEXPECTED(GC_REFCOUNT(obj) == 1)) {
		/* This handler has no rv in which to return owned storage. A lazy
		 * initializer or error handler can release the proxy while resolving
		 * the property; do not hand the VM a dangling pointer or ask it to
		 * retry read_property() on the object that is about to disappear. */
		if (!EG(exception)) {
			zend_throw_error(NULL, "Proxy object was released while accessing property %s::$%s",
				ZSTR_VAL(po->cls->type_ce->name), ZSTR_VAL(name));
		}
		ptr = &EG(error_zval);
	}
	if (ptr && !Z_ISERROR_P(ptr)) {
		proxy_publish_prop_info(po, name, scope, cache_slot);
	}
	OBJ_RELEASE(obj);
	return ptr;
}

/* ------------------------------------------------------------------------- */
/* Property enumeration                                                      */
/*                                                                           */
/* foreach, get_object_vars(), json_encode() and var_export() read properties */
/* the way a direct access does (PHP runs get hooks for them), so they go     */
/* through the interceptor; see proxy_iterator.c for foreach. The other       */
/* three receive tables with IS_PTR entries for declared properties, which    */
/* the engine's consumers resolve through read_property() at the moment they  */
/* emit the value (exactly how PHP treats hooked properties): each property   */
/* is read once, after the consumer's recursion guard is in place, and a      */
/* throwing interceptor aborts the consumer like a throwing hook does.        */
/* var_dump()/print_r()/debug_zval_dump(), (array) casts and the plain        */
/* get_properties handler are raw views of the target's storage.             */
/*                                                                           */
/* No table handed out here may point into the target: engine code maps      */
/* IS_INDIRECT slots back to the object it inspects (array_walk(), var_dump(), */
/* reflection and foreach compute a slot's property info from the object's   */
/* own layout). Raw views therefore mirror the target's slots into the proxy's */
/* own, otherwise unused, property slots.                                    */
/* ------------------------------------------------------------------------- */

/* Copies a value out of a target table. A reference container that is still
 * aliased stays a reference (var_dump() marks it like PHP does); an
 * unaliased one is unwrapped. */
static void proxy_snapshot_value(zval *dst, zval *src)
{
	if (Z_ISREF_P(src) && Z_REFCOUNT_P(src) == 1) {
		src = Z_REFVAL_P(src);
	}
	ZVAL_COPY(dst, src);
}

/* The slot number of `slot` within `base`, or -1. */
static zend_always_inline int32_t proxy_slot_num(const zend_object *base, const zval *slot)
{
	if (base && slot >= base->properties_table
			&& slot < base->properties_table + base->ce->default_properties_count) {
		return (int32_t) (slot - base->properties_table);
	}
	return -1;
}

/* Detach a cached value before anything can invoke user destructors. The
 * retired table is released only after the whole snapshot is consistent. */
static void proxy_retire_value(HashTable *retired, zval *value)
{
	if (Z_REFCOUNTED_P(value)) {
		zval old;
		if (Z_ISREF_P(value) && Z_REFCOUNT_P(value) > 1) {
			/* The cache must not turn a single-owner target reference into
			 * an observable alias while the replacement snapshot is built.
			 * Keep its value alive without keeping the extra container owner. */
			ZVAL_COPY(&old, Z_REFVAL_P(value));
			Z_DELREF_P(value);
		} else {
			ZVAL_COPY_VALUE(&old, value);
		}
		ZVAL_NULL(value);
		zend_hash_next_index_insert_new(retired, &old);
	}
}

/* Release only the snapshot's container alias, retaining its value. No
 * destructor can run: another owner keeps the reference alive, and the value
 * is copied before decrementing it. Raw views and exception-time walk cleanup
 * must not mistake the cache's extra owner for a user-visible reference. */
static void proxy_strip_snapshot_references(zend_object *obj)
{
	if (!obj->properties) {
		return;
	}
	zval *value;
	ZEND_HASH_FOREACH_VAL(obj->properties, value) {
		if (Z_ISREF_P(value) && Z_REFCOUNT_P(value) > 1) {
			zend_reference *ref = Z_REF_P(value);
			ZVAL_COPY(value, &ref->val);
			GC_DELREF(ref);
		}
	} ZEND_HASH_FOREACH_END();
}

/* Restores a mirror slot before releasing its old value. Reentrant property
 * access must never see the value whose destructor is currently running. */
static void proxy_clear_mirror_slot(zend_object *obj, zval *slot, HashTable *retired)
{
	if (Z_ISREF_P(slot)) {
		zend_property_info *info = zend_get_property_info_for_slot(obj, slot);
		if (info && ZEND_TYPE_IS_SET(info->type)) {
			ZEND_REF_DEL_TYPE_SOURCE(Z_REF_P(slot), info);
		}
	}
	proxy_retire_value(retired, slot);
	ZVAL_UNDEF(slot);
	Z_PROP_FLAG_P(slot) = IS_PROP_UNINIT;
}

static void proxy_clear_mirror(zend_object *obj, HashTable *retired)
{
	for (int i = 0; i < obj->ce->default_properties_count; i++) {
		proxy_clear_mirror_slot(obj, OBJ_PROP_NUM(obj, i), retired);
	}
}

/* get_properties() has no purpose argument. These two native consumers write
 * directly to its entries, bypassing property handlers. Recognizing their
 * frame lets them alias the target without making read-only raw views alias
 * every property. No engine function or opcode handler is replaced. */
static bool proxy_properties_are_writable(void)
{
	zend_execute_data *ex = EG(current_execute_data);
	return ex && ex->func && ex->func->type == ZEND_INTERNAL_FUNCTION
		&& !ex->func->common.scope && ex->func->common.function_name
		&& (zend_string_equals_literal(ex->func->common.function_name, "array_walk")
			|| zend_string_equals_literal(ex->func->common.function_name, "array_walk_recursive"));
}

/* Raw get_mangled_object_vars() copies reference containers just as the
 * engine does. Reflection needs indirect declared slots to distinguish them
 * from dynamic entries, so its ordinary mirror representation is retained. */
static bool proxy_properties_keep_references(void)
{
	zend_execute_data *ex = EG(current_execute_data);
	return ex && ex->func && ex->func->type == ZEND_INTERNAL_FUNCTION
		&& !ex->func->common.scope && ex->func->common.function_name
		&& zend_string_equals_literal(ex->func->common.function_name, "get_mangled_object_vars");
}

/* Saved frame pointers are identity tokens only: a finished frame may already
 * have been freed, so inspect the live stack without dereferencing the token. */
static bool proxy_raw_walk_frame_active(const zend_execute_data *frame)
{
	for (zend_execute_data *current = EG(current_execute_data); current; current = current->prev_execute_data) {
		if (current == frame) {
			return true;
		}
	}
	return false;
}

static bool proxy_raw_walk_iterator_bound(const proxy_object *po, uint32_t iterator)
{
	for (proxy_raw_walk *walk = po->raw_walks; walk; walk = walk->next) {
		if (walk->iterator == iterator) {
			return true;
		}
	}
	return false;
}

static void proxy_raw_walk_bind_iterator(proxy_object *po, proxy_raw_walk *walk, HashTable *table)
{
	if (walk->iterator != UINT32_MAX) {
		return;
	}
	for (uint32_t iterator = 0; iterator < EG(ht_iterators_used); iterator++) {
		if (EG(ht_iterators)[iterator].ht == table && !proxy_raw_walk_iterator_bound(po, iterator)) {
			walk->iterator = iterator;
			return;
		}
	}
}

/* Bind each active native walk to its hash iterator. Its first get_properties
 * call precedes iterator creation; the following fetch binds the new iterator.
 * Nested walks bind the pending outer walk before registering their own frame.
 * Completed frames are recognized only by comparison with the live stack. */
static HashPosition proxy_raw_walk_position(proxy_object *po, HashTable *ht)
{
	proxy_raw_walk **link = &po->raw_walks;
	while (*link) {
		proxy_raw_walk *walk = *link;
		if (!proxy_raw_walk_frame_active(walk->frame) || walk->table != ht
				|| (walk->iterator != UINT32_MAX
					&& (walk->iterator >= EG(ht_iterators_used)
						|| EG(ht_iterators)[walk->iterator].ht != ht))) {
			*link = walk->next;
			efree(walk);
		} else {
			link = &walk->next;
		}
	}
	proxy_raw_walk *current = NULL;
	for (proxy_raw_walk *walk = po->raw_walks; walk; walk = walk->next) {
		proxy_raw_walk_bind_iterator(po, walk, ht);
		if (walk->frame == EG(current_execute_data)) {
			current = walk;
		}
	}
	if (!current) {
		current = emalloc(sizeof(proxy_raw_walk));
		current->frame = EG(current_execute_data);
		current->table = ht;
		current->iterator = UINT32_MAX;
		current->next = po->raw_walks;
		po->raw_walks = current;
	}
	return current->iterator == UINT32_MAX ? 0 : EG(ht_iterators)[current->iterator].pos;
}

static void proxy_snapshot_reference(zval *dst, zval *src, zend_object *base)
{
	if (!Z_ISREF_P(src)) {
		ZVAL_MAKE_REF(src);
		if (base) {
			zend_property_info *info = zend_get_typed_property_info_for_slot(base, src);
			if (info) {
				ZEND_REF_ADD_TYPE_SOURCE(Z_REF_P(src), info);
			}
		}
	}
	ZVAL_COPY(dst, src);
}

/* Converts a table describing the target's storage into a table describing
 * the proxy. `base` is the object whose slots the source's IS_INDIRECT entries
 * point into (the real instance behind a lazy target, or the lazy object
 * itself while uninitialized). With `lazy_declared` the declared properties
 * become IS_PTR entries that the consumer reads through the proxy; otherwise
 * their values are copied. Uninitialized slots become IS_INDIRECT entries
 * pointing at the proxy's own slot of the same offset, which is uninitialized
 * as well: consumers skip it and var_dump() prints "uninitialized(type)".
 * With `mirror` an initialized declared value is copied into the proxy's own
 * slot and referenced from there, so that consumers mapping IS_INDIRECT slots
 * back to the object (array_walk(), reflection) find declared properties. */
static void proxy_snapshot_into(HashTable *dst, zend_object *obj, zend_object *base, HashTable *src, bool lazy_declared, bool mirror, HashTable *retired)
{
	zend_string *key;
	zend_ulong h;
	zval *val, tmp;
	bool empty_ind = false;

	ZEND_HASH_FOREACH_KEY_VAL(src, h, key, val) {
		zval *slot = NULL;
		zend_property_info *info = NULL;
		if (Z_TYPE_P(val) == IS_INDIRECT) {
			slot = Z_INDIRECT_P(val);
		} else if (Z_TYPE_P(val) == IS_PTR) {
			info = Z_PTR_P(val);
			if (lazy_declared && key) {
				zend_hash_update_ptr(dst, key, info);
				continue;
			}
			if ((info->flags & ZEND_ACC_VIRTUAL) || !base) {
				continue;
			}
			slot = OBJ_PROP(base, info->offset);
		}
		if (slot) {
			int32_t num = proxy_slot_num(base, slot);
			if (Z_TYPE_P(slot) == IS_UNDEF) {
				if (num < 0 || num >= obj->ce->default_properties_count) {
					continue;
				}
				if (mirror) {
					proxy_clear_mirror_slot(obj, OBJ_PROP_NUM(obj, num), retired);
				} else if (Z_TYPE_P(OBJ_PROP_NUM(obj, num)) != IS_UNDEF) {
					continue;
				}
				ZVAL_INDIRECT(&tmp, OBJ_PROP_NUM(obj, num));
				empty_ind = true;
			} else if (lazy_declared && key && num >= 0) {
				zend_property_info *slot_info = base->ce->properties_info_table[num];
				if (slot_info) {
					zend_hash_update_ptr(dst, key, slot_info);
					continue;
				}
				proxy_snapshot_value(&tmp, slot);
			} else if (mirror && num >= 0 && num < obj->ce->default_properties_count) {
				zval *mslot = OBJ_PROP_NUM(obj, num);
				proxy_clear_mirror_slot(obj, mslot, retired);
				ZVAL_COPY_DEREF(mslot, slot);
				Z_PROP_FLAG_P(mslot) = 0;
				ZVAL_INDIRECT(&tmp, mslot);
			} else {
				proxy_snapshot_value(&tmp, slot);
			}
		} else if (Z_TYPE_P(val) == IS_UNDEF) {
			continue;
		} else {
			proxy_snapshot_value(&tmp, val);
		}
		if (retired) {
			zval *old = key ? zend_hash_find(dst, key) : zend_hash_index_find(dst, h);
			if (old) {
				proxy_retire_value(retired, old);
			}
		}
		if (key) {
			zend_hash_update(dst, key, &tmp);
		} else {
			zend_hash_index_update(dst, h, &tmp);
		}
	} ZEND_HASH_FOREACH_END();
	if (empty_ind) {
		/* after the inserts: initializing an empty table resets its flags */
		HT_FLAGS(dst) |= HASH_FLAG_HAS_EMPTY_IND;
	}
}

/* Reads one property for enumeration through the proxy's own handler, so
 * that interceptors, hooks and type contracts apply. Returns 1 with the value
 * in dst, 0 for an uninitialized or missing result (dst untouched) and -1
 * when an exception was thrown. */
int proxy_enum_read(zend_object *obj, zend_string *name, zend_class_entry *scope, zval *dst)
{
	zval rv;
	const zend_class_entry *old_scope = EG(fake_scope);
	EG(fake_scope) = scope;
	zval *v = obj->handlers->read_property(obj, name, BP_VAR_IS, NULL, &rv);
	EG(fake_scope) = (zend_class_entry *) old_scope;
	if (EG(exception)) {
		if (v == &rv) {
			zval_ptr_dtor(&rv);
		}
		return -1;
	}
	if (v == &EG(uninitialized_zval)) {
		return 0;
	}
	if (v == &rv) {
		if (Z_ISREF(rv)) {
			zend_unwrap_reference(&rv);
		}
		ZVAL_COPY_VALUE(dst, &rv);
	} else {
		ZVAL_COPY_DEREF(dst, v);
	}
	return 1;
}

/* Declared properties in PHP's enumeration order (ancestors first, in
 * declaration order; a redeclaration keeps the ancestor's position; a
 * protected property redeclared public appears under its plain name), the
 * same walk as the engine's hooked-object enumeration. Each class contributes
 * the properties it declares itself; inherited entries were already emitted
 * by the ancestor. Callback per property: key to use (mangled for non-public),
 * its info and the backing slot of the target's storage (NULL for mocks,
 * uninitialized lazy targets and virtual properties). */
bool proxy_foreach_declared(proxy_object *po, zend_object *storage, proxy_declared_cb cb, void *ctx)
{
	zend_class_entry *type_ce = po->cls->type_ce;
	int32_t depth = 0;
	for (zend_class_entry *pce = type_ce; pce; pce = pce->parent) {
		depth++;
	}
	zend_class_entry **chain = emalloc(sizeof(zend_class_entry *) * depth);
	int32_t i = 0;
	for (zend_class_entry *pce = type_ce; pce; pce = pce->parent) {
		chain[i++] = pce;
	}
	bool ok = true;
	for (i = depth - 1; ok && i >= 0; i--) {
		zend_property_info *info;
		ZEND_HASH_MAP_FOREACH_PTR(&chain[i]->properties_info, info) {
			if ((info->flags & ZEND_ACC_STATIC) || info->ce != chain[i]) {
				continue;
			}
			zend_string *key = info->name;
			if (info->flags & ZEND_ACC_PROTECTED) {
				const char *plain = zend_get_unmangled_property_name(key);
				zend_string *plain_key = zend_string_init(plain, strlen(plain), 0);
				zend_property_info *child = zend_hash_find_ptr(&type_ce->properties_info, plain_key);
				if (child && (child->flags & ZEND_ACC_PUBLIC)) {
					key = plain_key;
				} else {
					zend_string_release(plain_key);
				}
			}
			zval *slot = NULL;
			if (storage && !(info->flags & ZEND_ACC_VIRTUAL)) {
				slot = OBJ_PROP(storage, info->offset);
			}
			ok = cb(ctx, key, info, slot);
			if (key != info->name) {
				zend_string_release(key);
			}
			if (!ok) {
				break;
			}
		} ZEND_HASH_FOREACH_END();
	}
	efree(chain);
	return ok;
}

typedef struct {
	zend_object *obj;
	proxy_object *po;
	zend_object *storage;
	HashTable *ht;
} proxy_enum_ctx;

/* Declared properties of an interceptable view. A backed property without
 * hook or interceptor is exposed like PHP exposes it, as an IS_INDIRECT entry
 * to the target's live slot: consumers skip it if it is uninitialized when
 * they reach it and apply their own reference rules. Everything else becomes
 * an IS_PTR entry that the consumer reads through the proxy (interceptors,
 * hooks, type contracts). Slots of the real instance behind a lazy proxy are
 * never exposed: an interceptor may replace that instance during the walk. */
static bool proxy_enum_declared_cb(void *ctx_, zend_string *key, zend_property_info *info, zval *slot)
{
	proxy_enum_ctx *ctx = ctx_;
	if (slot && !info->hooks && ctx->storage == ctx->po->target
			&& !proxy_has_property_interceptor(ctx->po)) {
		zval tmp;
		ZVAL_INDIRECT(&tmp, slot);
		zend_hash_update(ctx->ht, key, &tmp);
		if (Z_TYPE_P(slot) == IS_UNDEF) {
			HT_FLAGS(ctx->ht) |= HASH_FLAG_HAS_EMPTY_IND;
		}
		return true;
	}
	if (slot && !info->hooks && Z_TYPE_P(slot) == IS_UNDEF) {
		zval *own = OBJ_PROP(ctx->obj, info->offset);
		if (Z_TYPE_P(own) == IS_UNDEF) {
			zval tmp;
			ZVAL_INDIRECT(&tmp, own);
			zend_hash_update(ctx->ht, key, &tmp);
			HT_FLAGS(ctx->ht) |= HASH_FLAG_HAS_EMPTY_IND;
		}
		return true;
	}
	zend_hash_update_ptr(ctx->ht, key, info);
	return true;
}

/* Appends the target's dynamic properties, read through the interceptor. Values are
 * captured now, before the consumer reads the declared entries, matching the
 * engine's order for hooked objects (dynamic values are copied when the table
 * is built; getters run while the consumer walks it). */
static bool proxy_enum_dynamic(proxy_enum_ctx *ctx, proxy_object *po)
{
	zend_object *storage = proxy_storage_object(po, false);
	if (!storage) {
		return true;
	}
	HashTable *props = storage->handlers->get_properties(storage);
	if (!props || EG(exception)) {
		return !EG(exception);
	}
	/* The reads below may modify the target's table: collect the dynamic
	 * keys first (declared properties are IS_INDIRECT entries of that table). */
	HashTable *keys = zend_new_array(zend_hash_num_elements(props));
	zend_string *key;
	zend_ulong h;
	zval *val, tmp, value;
	ZEND_HASH_FOREACH_KEY_VAL(props, h, key, val) {
		if (Z_TYPE_P(val) == IS_INDIRECT || Z_TYPE_P(val) == IS_PTR) {
			continue;
		}
		if (key) {
			ZVAL_STR_COPY(&tmp, key);
		} else {
			ZVAL_LONG(&tmp, (zend_long) h);
		}
		zend_hash_next_index_insert_new(keys, &tmp);
	} ZEND_HASH_FOREACH_END();
	bool ok = true;
	ZEND_HASH_FOREACH_VAL(keys, val) {
		zend_string *name = Z_TYPE_P(val) == IS_STRING ? zend_string_copy(Z_STR_P(val)) : zend_long_to_str(Z_LVAL_P(val));
		int rc = proxy_enum_read(ctx->obj, name, NULL, &value);
		if (rc < 0) {
			zend_string_release(name);
			ok = false;
			break;
		}
		if (rc > 0) {
			/* a property removed by an earlier read is not reported */
			if (Z_TYPE_P(val) == IS_STRING) {
				zend_hash_update(ctx->ht, name, &value);
			} else {
				zend_hash_index_update(ctx->ht, Z_LVAL_P(val), &value);
			}
		}
		zend_string_release(name);
	} ZEND_HASH_FOREACH_END();
	zend_array_destroy(keys);
	return ok;
}

static bool proxy_purpose_is_raw(zend_prop_purpose purpose)
{
	return purpose == ZEND_PROP_PURPOSE_DEBUG
		|| purpose == ZEND_PROP_PURPOSE_ARRAY_CAST
		|| purpose == ZEND_PROP_PURPOSE_SERIALIZE;
}

/* array_walk() reloads its table after each callback. Alias only the next
 * initialized entry, matching when PHP itself makes that property a reference.
 * Eagerly aliasing later entries would leak references into nested raw reads. */
static void proxy_raw_walk_alias(proxy_object *po, zend_object *obj, HashTable *source,
		HashTable *ht, zend_object *base, HashTable *retired)
{
	HashPosition pos = proxy_raw_walk_position(po, ht);
	zval *value;
	while ((value = zend_hash_get_current_data_ex(ht, &pos))) {
		zval *slot = Z_TYPE_P(value) == IS_INDIRECT ? Z_INDIRECT_P(value) : value;
		if (Z_TYPE_P(slot) == IS_UNDEF) {
			zend_hash_move_forward_ex(ht, &pos);
			continue;
		}
		zend_string *key;
		zend_ulong index;
		int kind = zend_hash_get_current_key_ex(ht, &key, &index, &pos);
		zval *src = kind == HASH_KEY_IS_STRING
			? zend_hash_find(source, key) : zend_hash_index_find(source, index);
		if (!src) {
			return;
		}
		zend_object *owner = NULL;
		if (Z_TYPE_P(src) == IS_INDIRECT) {
			src = Z_INDIRECT_P(src);
			owner = proxy_slot_num(base, src) >= 0 ? base : NULL;
		} else if (Z_TYPE_P(src) == IS_PTR) {
			zend_property_info *info = Z_PTR_P(src);
			if (!base || (info->flags & ZEND_ACC_VIRTUAL)) {
				return;
			}
			src = OBJ_PROP(base, info->offset);
			owner = base;
		}
		if (Z_TYPE_P(src) == IS_UNDEF) {
			return;
		}
		if (Z_TYPE_P(value) == IS_INDIRECT) {
			proxy_clear_mirror_slot(obj, Z_INDIRECT_P(value), retired);
		}
		proxy_retire_value(retired, value);
		proxy_snapshot_reference(value, src, owner);
		return;
	}
}

/* Raw views mirror declared values into proxy-owned slots. Writable native
 * consumers receive owned reference entries instead: no IS_INDIRECT entry
 * ever points into another object's layout, and the sole property type source
 * belongs to the target slot. Removing that slot releases its constraint even
 * when the callback keeps the reference. */
static HashTable *proxy_raw_snapshot(proxy_object *po, zend_object *obj, HashTable *source)
{
	HashTable *ht = obj->properties;
	if (ht && EG(exception)) {
		/* array_walk() still re-fetches its table while unwinding a callback
		 * exception. Clearing it would reset a surrounding walk's iterator. */
		proxy_strip_snapshot_references(obj);
		return ht;
	}
	if (ht && (GC_REFCOUNT(ht) > 1 || GC_IS_RECURSIVE(ht))) {
		return ht;
	}
	HashTable *retired = zend_new_array(obj->ce->default_properties_count);
	proxy_clear_mirror(obj, retired);
	if (!ht) {
		ht = obj->properties = zend_new_array(source ? zend_hash_num_elements(source) : 0);
	} else {
		/* Keep surviving entries in their original positions: array_walk()
		 * reloads this table after every callback and carries a hash iterator. */
		zend_string *key;
		zend_ulong h;
		zval *val;
		ZEND_HASH_FOREACH_KEY_VAL(ht, h, key, val) {
			proxy_retire_value(retired, val);
			if (!source || !(key ? zend_hash_find(source, key) : zend_hash_index_find(source, h))) {
				if (key) {
					zend_hash_del(ht, key);
				} else {
					zend_hash_index_del(ht, h);
				}
			}
		} ZEND_HASH_FOREACH_END();
	}
	if (source) {
		zend_object *base = proxy_storage_object(po, false);
		base = base ? base : po->target;
		proxy_snapshot_into(ht, obj, base, source,
			false, !proxy_properties_keep_references(), retired);
		if (proxy_properties_are_writable()) {
			proxy_raw_walk_alias(po, obj, source, ht, base, retired);
		}
	}
	/* Destructors may reenter enumeration or release the last user reference
	 * to the proxy. Its caller owns an object guard; this table guard keeps a
	 * reentrant handler from replacing the snapshot we are about to return. */
	GC_ADDREF(ht);
	zend_array_destroy(retired);
	GC_DELREF(ht);
	return ht;
}

/* The raw snapshot keeps copies of the target's values in the proxy's own
 * slots. Release it when nobody uses it any more, so that those values do not
 * outlive the target's own references to them longer than necessary. */
static void proxy_release_raw_snapshot(zend_object *obj)
{
	HashTable *ht = obj->properties;
	if (!ht || GC_REFCOUNT(ht) != 1 || GC_IS_RECURSIVE(ht) || HT_HAS_ITERATORS(ht)) {
		return;
	}
	proxy_object *po = proxy_object_from_obj(obj);
	if (po) {
		proxy_free_raw_walks(po);
	}
	HashTable *retired = zend_new_array(obj->ce->default_properties_count);
	obj->properties = NULL;
	proxy_clear_mirror(obj, retired);
	zend_array_destroy(ht);
	zend_array_destroy(retired);
}

static HashTable *proxy_get_properties(zend_object *obj)
{
	proxy_object *po;
	bool std;

	GC_ADDREF(obj);
	if (!proxy_handler_state_ex(obj, &po, &std, false) || !po->target) {
		/* a plain object of the generated class, a bare or lazy proxy
		 * (exception thrown) or a mock: the object's own slots */
		HashTable *result = zend_std_get_properties(obj);
		OBJ_RELEASE(obj);
		return result;
	}
	proxy_release_raw_snapshot(obj);
	HashTable *source = EG(exception) ? NULL : po->target->handlers->get_properties(po->target);
	HashTable *result = proxy_raw_snapshot(po, obj, EG(exception) ? NULL : source);
	if (UNEXPECTED(GC_REFCOUNT(obj) == 1)) {
		/* A lazy initializer released the proxy while we hold the only
		 * reference; the caller still expects a table owned by a live object.
		 * Keep the object alive (it is released at shutdown) and report. */
		if (!EG(exception)) {
			zend_throw_error(NULL, "Proxy object was released while enumerating its properties");
		}
		return result;
	}
	GC_DELREF(obj);
	return result;
}

static zend_array *proxy_get_properties_for(zend_object *obj, zend_prop_purpose purpose)
{
	proxy_object *po;
	bool std;

	GC_ADDREF(obj);
	if (!proxy_handler_state(obj, &po, &std)) {
		/* consumers such as the serializer expect a table even when they
		 * are about to abort */
		zend_array *result = std ? zend_std_get_properties_for(obj, purpose) : (zend_array *) &zend_empty_array;
		OBJ_RELEASE(obj);
		return result;
	}
	if (purpose == ZEND_PROP_PURPOSE_SERIALIZE) {
		/* the serializer expects a table even when it is going to abort */
		proxy_throw(proxy_ce_UnsupportedOperation, "Proxy objects of class %s cannot be serialized", ZSTR_VAL(obj->ce->name));
		OBJ_RELEASE(obj);
		return (zend_array *) &zend_empty_array;
	}

	proxy_strip_snapshot_references(obj);
	HashTable *result = NULL;
	zend_object *target = po->target;
	bool raw = proxy_purpose_is_raw(purpose);
	bool delegate = target && (raw || target->handlers->get_properties_for);

	if (delegate) {
		/* Raw views, and every view of a class that defines its own (internal
		 * classes such as ArrayObject expose their storage), come from the
		 * target and are re-based onto the proxy. */
		zval tzv;
		ZVAL_OBJ(&tzv, target);
		bool no_init = purpose == ZEND_PROP_PURPOSE_ARRAY_CAST && !target->handlers->get_properties_for;
		HashTable *source = no_init ? zend_get_properties_no_lazy_init(target) : zend_get_properties_for(&tzv, purpose);
		if (no_init && source) {
			GC_TRY_ADDREF(source);
		}
		if (source && !EG(exception)) {
			zend_object *base = proxy_storage_object(po, false);
			result = zend_new_array(zend_hash_num_elements(source));
			proxy_snapshot_into(result, obj, base ? base : target, source, !raw, false, NULL);
		}
		if (source) {
			zend_release_properties(source);
		}
	} else if (!target && raw) {
		/* A mock has no storage: its own slots are all uninitialized. */
		result = zend_std_get_properties(obj);
		GC_TRY_ADDREF(result);
	} else {
		/* PHP initializes a lazy object for these views */
		zend_object *storage = target ? proxy_storage_object(po, true) : NULL;
		if (!EG(exception)) {
			proxy_enum_ctx ctx = { obj, po, storage, zend_new_array(8) };
			proxy_foreach_declared(po, storage, proxy_enum_declared_cb, &ctx);
			if (!proxy_enum_dynamic(&ctx, po)) {
				zend_array_destroy(ctx.ht);
			} else {
				result = ctx.ht;
			}
		}
	}

	if (UNEXPECTED(GC_REFCOUNT(obj) == 1)) {
		if (!EG(exception)) {
			zend_throw_error(NULL, "Proxy object was released while enumerating its properties");
		}
		/* see proxy_get_properties() */
		return result;
	}
	GC_DELREF(obj);
	return result;
}

/* ------------------------------------------------------------------------- */
/* Casts, counting, comparison                                               */
/* ------------------------------------------------------------------------- */

/* Handlers that dispatch methods directly (count(), casts, ArrayAccess)
 * refuse a proxy that the engine turned into a lazy object, like every other
 * entry point. Returns true after throwing. */
static bool proxy_lazy_refused(zend_object *obj)
{
	return UNEXPECTED(zend_object_is_lazy(obj)) && proxy_is_proxy(obj) && proxy_refuse_lazy(obj);
}

static zend_result proxy_cast_object(zend_object *obj, zval *retval, int type)
{
	proxy_class *cls = proxy_class_from_handlers(obj->handlers);
	proxy_object *po = proxy_object_from_obj(obj);
	if (UNEXPECTED(!po && obj->handlers == &std_object_handlers)) {
		return zend_std_cast_object_tostring(obj, retval, type);
	}
	if (proxy_lazy_refused(obj)) {
		return FAILURE;
	}

	if (type == IS_STRING) {
		zend_function *fn = zend_hash_str_find_ptr(&cls->ce->function_table, ZEND_STRL(ZEND_TOSTRING_FUNC_NAME));
		if (fn && proxy_function_is_trampoline(fn) && proxy_object_usable(po)) {
			/* the interceptor may release the last external reference */
			zend_class_entry *type_ce = proxy_type_of(po);
			zval result;
			GC_ADDREF(obj);
			proxy_dispatch_method(po, obj, (proxy_method *) fn, 0, NULL, &result);
			OBJ_RELEASE(obj);
			if (Z_ISUNDEF(result)) {
				return FAILURE;
			}
			if (Z_ISREF(result)) {
				zend_unwrap_reference(&result);
			}
			if (Z_TYPE(result) == IS_STRING) {
				ZVAL_COPY_VALUE(retval, &result);
				return SUCCESS;
			}
			zval_ptr_dtor(&result);
			if (!EG(exception)) {
				zend_throw_error(NULL, "Method %s::__toString() must return a string value", ZSTR_VAL(type_ce->name));
			}
			return FAILURE;
		}
		if (proxy_object_usable(po) && po->target) {
			return po->target->handlers->cast_object(po->target, retval, type);
		}
		return FAILURE;
	}
	if (type == _IS_BOOL) {
		ZVAL_TRUE(retval);
		return SUCCESS;
	}
	if (proxy_object_usable(po) && po->target) {
		return po->target->handlers->cast_object(po->target, retval, type);
	}
	return FAILURE;
}

static zend_result proxy_count_elements(zend_object *obj, zend_long *count)
{
	proxy_class *cls = proxy_class_from_handlers(obj->handlers);
	proxy_object *po = proxy_object_from_obj(obj);
	if (UNEXPECTED(!po && obj->handlers == &std_object_handlers)) {
		return FAILURE;
	}
	if (proxy_lazy_refused(obj)) {
		return FAILURE;
	}

	if (proxy_object_usable(po) && instanceof_function(cls->ce, zend_ce_countable)) {
		zend_function *fn = zend_hash_str_find_ptr(&cls->ce->function_table, ZEND_STRL("count"));
		if (fn && proxy_function_is_trampoline(fn)) {
			zval result;
			proxy_dispatch_method(po, obj, (proxy_method *) fn, 0, NULL, &result);
			if (Z_ISUNDEF(result)) {
				return FAILURE;
			}
			*count = zval_get_long(&result);
			zval_ptr_dtor(&result);
			return SUCCESS;
		}
	}
	if (proxy_object_usable(po) && po->target && po->target->handlers->count_elements) {
		return po->target->handlers->count_elements(po->target, count);
	}
	return FAILURE;
}

static int proxy_compare(zval *o1, zval *o2)
{
	if (Z_TYPE_P(o1) == IS_OBJECT && !proxy_is_proxy(Z_OBJ_P(o1))) {
		return zend_std_compare_objects(o1, o2);
	}
	if (Z_TYPE_P(o1) != Z_TYPE_P(o2)) {
		/* object vs. scalar: standard cast based comparison (uses our cast handler) */
		return zend_std_compare_objects(o1, o2);
	}
	if (Z_OBJ_P(o1) == Z_OBJ_P(o2)) {
		return 0;
	}
	/* A proxy is a distinct object of a distinct (generated) class. Like the
	 * engine's default for objects of different classes, it never compares
	 * equal to anything but itself; this keeps == symmetric regardless of
	 * which operand is the proxy. */
	return ZEND_UNCOMPARABLE;
}

/* ------------------------------------------------------------------------- */
/* Dimensions (ArrayAccess)                                                  */
/* ------------------------------------------------------------------------- */

static proxy_method *proxy_array_access_method(proxy_class *cls, proxy_object *po, const char *lcname, size_t len)
{
	if (!proxy_object_usable(po) || !instanceof_function(cls->ce, zend_ce_arrayaccess)) {
		return NULL;
	}
	zend_function *fn = zend_hash_str_find_ptr(&cls->ce->function_table, lcname, len);
	if (fn && proxy_function_is_trampoline(fn)) {
		return (proxy_method *) fn;
	}
	return NULL;
}

static ZEND_COLD void proxy_bad_array_access(zend_object *obj)
{
	zend_throw_error(NULL, "Cannot use object of type %s as array", ZSTR_VAL(obj->ce->name));
}

static zval *proxy_read_dimension(zend_object *obj, zval *offset, int type, zval *rv)
{
	proxy_class *cls = proxy_class_from_handlers(obj->handlers);
	proxy_object *po = proxy_object_from_obj(obj);
	if (UNEXPECTED(!po && obj->handlers == &std_object_handlers)) {
		return zend_std_read_dimension(obj, offset, type, rv);
	}
	if (proxy_lazy_refused(obj)) {
		return NULL;
	}
	proxy_method *get = proxy_array_access_method(cls, po, ZEND_STRL("offsetget"));

	if (get) {
		zval tmp_offset;
		if (!offset) {
			ZVAL_NULL(&tmp_offset);
		} else {
			ZVAL_COPY_DEREF(&tmp_offset, offset);
		}
		GC_ADDREF(obj);
		if (type == BP_VAR_IS) {
			proxy_method *exists = proxy_array_access_method(cls, po, ZEND_STRL("offsetexists"));
			zval r;
			proxy_dispatch_method(po, obj, exists, 1, &tmp_offset, &r);
			if (Z_ISUNDEF(r)) {
				OBJ_RELEASE(obj);
				zval_ptr_dtor(&tmp_offset);
				return NULL;
			}
			bool found = zend_is_true(&r);
			zval_ptr_dtor(&r);
			if (!found) {
				OBJ_RELEASE(obj);
				zval_ptr_dtor(&tmp_offset);
				return &EG(uninitialized_zval);
			}
		}
		proxy_dispatch_method(po, obj, get, 1, &tmp_offset, rv);
		OBJ_RELEASE(obj);
		zval_ptr_dtor(&tmp_offset);
		if (UNEXPECTED(Z_TYPE_P(rv) == IS_UNDEF)) {
			if (!EG(exception)) {
				zend_throw_error(NULL, "Undefined offset for object of type %s used as array", ZSTR_VAL(obj->ce->name));
			}
			return NULL;
		}
		return rv;
	}
	if (proxy_object_usable(po) && po->target) {
		return po->target->handlers->read_dimension(po->target, offset, type, rv);
	}
	proxy_bad_array_access(obj);
	return NULL;
}

static void proxy_write_dimension(zend_object *obj, zval *offset, zval *value)
{
	proxy_class *cls = proxy_class_from_handlers(obj->handlers);
	proxy_object *po = proxy_object_from_obj(obj);
	if (UNEXPECTED(!po && obj->handlers == &std_object_handlers)) {
		zend_std_write_dimension(obj, offset, value);
		return;
	}
	if (proxy_lazy_refused(obj)) {
		return;
	}
	proxy_method *set = proxy_array_access_method(cls, po, ZEND_STRL("offsetset"));

	if (set) {
		zval args[2];
		if (!offset) {
			ZVAL_NULL(&args[0]);
		} else {
			ZVAL_COPY_DEREF(&args[0], offset);
		}
		ZVAL_COPY_DEREF(&args[1], value);
		GC_ADDREF(obj);
		zval r;
		proxy_dispatch_method(po, obj, set, 2, args, &r);
		zval_ptr_dtor(&r);
		OBJ_RELEASE(obj);
		zval_ptr_dtor(&args[0]);
		zval_ptr_dtor(&args[1]);
		return;
	}
	if (proxy_object_usable(po) && po->target) {
		po->target->handlers->write_dimension(po->target, offset, value);
		return;
	}
	proxy_bad_array_access(obj);
}

static int proxy_has_dimension(zend_object *obj, zval *offset, int check_empty)
{
	proxy_class *cls = proxy_class_from_handlers(obj->handlers);
	proxy_object *po = proxy_object_from_obj(obj);
	if (UNEXPECTED(!po && obj->handlers == &std_object_handlers)) {
		return zend_std_has_dimension(obj, offset, check_empty);
	}
	if (proxy_lazy_refused(obj)) {
		return 0;
	}
	proxy_method *exists = proxy_array_access_method(cls, po, ZEND_STRL("offsetexists"));

	if (exists) {
		zval tmp_offset, r;
		ZVAL_COPY_DEREF(&tmp_offset, offset);
		GC_ADDREF(obj);
		proxy_dispatch_method(po, obj, exists, 1, &tmp_offset, &r);
		bool result = false;
		if (!Z_ISUNDEF(r)) {
			result = zend_is_true(&r);
			zval_ptr_dtor(&r);
			if (check_empty && result && EXPECTED(!EG(exception))) {
				proxy_method *get = proxy_array_access_method(cls, po, ZEND_STRL("offsetget"));
				proxy_dispatch_method(po, obj, get, 1, &tmp_offset, &r);
				if (!Z_ISUNDEF(r)) {
					result = zend_is_true(&r);
					zval_ptr_dtor(&r);
				} else {
					result = false;
				}
			}
		}
		OBJ_RELEASE(obj);
		zval_ptr_dtor(&tmp_offset);
		return result;
	}
	if (proxy_object_usable(po) && po->target) {
		return po->target->handlers->has_dimension(po->target, offset, check_empty);
	}
	proxy_bad_array_access(obj);
	return 0;
}

static void proxy_unset_dimension(zend_object *obj, zval *offset)
{
	proxy_class *cls = proxy_class_from_handlers(obj->handlers);
	proxy_object *po = proxy_object_from_obj(obj);
	if (UNEXPECTED(!po && obj->handlers == &std_object_handlers)) {
		zend_std_unset_dimension(obj, offset);
		return;
	}
	if (proxy_lazy_refused(obj)) {
		return;
	}
	proxy_method *unset = proxy_array_access_method(cls, po, ZEND_STRL("offsetunset"));

	if (unset) {
		zval tmp_offset, r;
		ZVAL_COPY_DEREF(&tmp_offset, offset);
		GC_ADDREF(obj);
		proxy_dispatch_method(po, obj, unset, 1, &tmp_offset, &r);
		zval_ptr_dtor(&r);
		OBJ_RELEASE(obj);
		zval_ptr_dtor(&tmp_offset);
		return;
	}
	if (proxy_object_usable(po) && po->target) {
		po->target->handlers->unset_dimension(po->target, offset);
		return;
	}
	proxy_bad_array_access(obj);
}

/* ------------------------------------------------------------------------- */
/* Handler table                                                             */
/* ------------------------------------------------------------------------- */

void proxy_class_init_handlers(proxy_class *cls)
{
	zend_object_handlers *h = &cls->handlers.h;

	memcpy(h, cls->parent_handlers, sizeof(zend_object_handlers));
	cls->handlers.cls = cls;

	if (cls->embedded) {
		h->offset = XtOffsetOf(proxy_object, std);
	}
	h->free_obj = proxy_free_obj;
	h->dtor_obj = proxy_dtor_obj;
	h->clone_obj = proxy_clone_obj;
#if PHP_VERSION_ID >= 80500
	h->clone_obj_with = proxy_clone_obj_with;
#endif
	h->read_property = proxy_read_property;
	h->write_property = proxy_write_property;
	h->read_dimension = proxy_read_dimension;
	h->write_dimension = proxy_write_dimension;
	h->get_property_ptr_ptr = proxy_get_property_ptr_ptr;
	h->has_property = proxy_has_property;
	h->unset_property = proxy_unset_property;
	h->has_dimension = proxy_has_dimension;
	h->unset_dimension = proxy_unset_dimension;
	h->get_properties = proxy_get_properties;
	h->get_method = proxy_get_method;
	h->get_constructor = proxy_get_constructor;
	h->get_class_name = zend_std_get_class_name;
	h->cast_object = proxy_cast_object;
	h->count_elements = proxy_count_elements;
	h->get_debug_info = NULL;
	h->get_closure = proxy_get_closure;
	h->get_gc = proxy_get_gc;
	h->do_operation = NULL;
	h->compare = proxy_compare;
	h->get_properties_for = proxy_get_properties_for;
}
