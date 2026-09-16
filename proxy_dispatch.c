/*
 * ext/proxy - interceptor dispatch
 *
 * Method calls:     methodInterceptor -> original / UnconfiguredMethod
 * Property access:  propertyInterceptor -> original / UnconfiguredProperty
 *
 * Invocation and PropertyInvocation objects describe one operation and let
 * interceptors delegate with proceed(). Each proceed() call invokes the target
 * operation directly, or throws the corresponding Unconfigured* error on mocks.
 */

#include "php_proxy.h"
#include "zend_enum.h"
#include "zend_closures.h"
#include "zend_smart_str.h"
#include "zend_weakrefs.h"
#include <ctype.h>

static zend_object_handlers proxy_invocation_handlers;
static zend_object_handlers proxy_prop_invocation_handlers;

static const char *const proxy_prop_op_names[] = { "GET", "SET", "ISSET", "UNSET" };

/* ------------------------------------------------------------------------- */
/* Type checks                                                               */
/* ------------------------------------------------------------------------- */

/* Resolves the class named by a type. PHP < 8.5 keeps `self` and `parent`
 * unresolved in method signatures; they refer to the declaring scope. */
static zend_class_entry *proxy_fetch_ce_from_type(const zend_type *type, zend_class_entry *scope)
{
	zend_string *name = ZEND_TYPE_NAME(*type);
	if (scope) {
		if (zend_string_equals_literal_ci(name, "self")) {
			return scope;
		}
		if (zend_string_equals_literal_ci(name, "parent")) {
			return scope->parent;
		}
	}
	if (ZSTR_HAS_CE_CACHE(name)) {
		zend_class_entry *ce = ZSTR_GET_CE_CACHE(name);
		if (ce) {
			return ce;
		}
	}
	return zend_lookup_class_ex(name, NULL, ZEND_FETCH_CLASS_NO_AUTOLOAD);
}

static bool proxy_check_intersection(const zend_type_list *list, zend_class_entry *arg_ce, zend_class_entry *scope)
{
	const zend_type *t;
	ZEND_TYPE_LIST_FOREACH((zend_type_list *) list, t) {
		zend_class_entry *ce = proxy_fetch_ce_from_type(t, scope);
		if (!ce || !instanceof_function(arg_ce, ce)) {
			return false;
		}
	} ZEND_TYPE_LIST_FOREACH_END();
	return true;
}

/* Equivalent of the engine's zend_check_type() for user code, with an explicit
 * strictness flag and `static` resolved against the proxied type. Scalars are
 * coerced in place in weak mode. */
static bool proxy_check_type_at_frame(const zend_type *type, zval *arg, zend_class_entry *scope, zend_class_entry *static_ce, bool strict, zend_execute_data *callable_frame)
{
	const zend_reference *ref = NULL;

	if (Z_ISREF_P(arg)) {
		ref = Z_REF_P(arg);
		arg = Z_REFVAL_P(arg);
	}
	if (EXPECTED(ZEND_TYPE_CONTAINS_CODE(*type, Z_TYPE_P(arg)))) {
		return true;
	}

	if (ZEND_TYPE_IS_COMPLEX(*type) && Z_TYPE_P(arg) == IS_OBJECT) {
		zend_class_entry *arg_ce = Z_OBJCE_P(arg);
		if (ZEND_TYPE_HAS_LIST(*type)) {
			if (ZEND_TYPE_IS_INTERSECTION(*type)) {
				if (proxy_check_intersection(ZEND_TYPE_LIST(*type), arg_ce, scope)) {
					return true;
				}
			} else {
				const zend_type *lt;
				ZEND_TYPE_LIST_FOREACH(ZEND_TYPE_LIST(*type), lt) {
					if (ZEND_TYPE_IS_INTERSECTION(*lt)) {
						if (proxy_check_intersection(ZEND_TYPE_LIST(*lt), arg_ce, scope)) {
							return true;
						}
					} else {
						zend_class_entry *ce = proxy_fetch_ce_from_type(lt, scope);
						if (ce && instanceof_function(arg_ce, ce)) {
							return true;
						}
					}
				} ZEND_TYPE_LIST_FOREACH_END();
			}
		} else {
			zend_class_entry *ce = proxy_fetch_ce_from_type(type, scope);
			if (ce && instanceof_function(arg_ce, ce)) {
				return true;
			}
		}
	}

	uint32_t mask = ZEND_TYPE_FULL_MASK(*type);
	if ((mask & MAY_BE_CALLABLE) && (callable_frame
			? zend_is_callable_at_frame(arg, NULL, callable_frame, 0, NULL, NULL)
			: zend_is_callable(arg, 0, NULL))) {
		return true;
	}
	if ((mask & MAY_BE_STATIC) && Z_TYPE_P(arg) == IS_OBJECT && static_ce && instanceof_function(Z_OBJCE_P(arg), static_ce)) {
		return true;
	}
	if (ref && ZEND_REF_HAS_TYPE_SOURCES(ref)) {
		/* no conversions for typed references */
		return false;
	}
	return zend_verify_scalar_type_hint(mask, arg, strict, /* is_internal_arg */ false);
}

static bool proxy_check_type(const zend_type *type, zval *arg, zend_class_entry *scope, zend_class_entry *static_ce, bool strict)
{
	return proxy_check_type_at_frame(type, arg, scope, static_ce, strict, NULL);
}

/* User method contracts are checked in the method's scope. The convenience
 * callable API skips internal frames, including our trampoline, so supply
 * the original function explicitly without changing the active frame. */
static bool proxy_check_method_type(const zend_function *orig, const zend_type *type, zval *arg, zend_object *method_obj, zend_class_entry *static_ce, bool strict)
{
	if (orig->type == ZEND_USER_FUNCTION) {
		zend_execute_data frame = {0};
		frame.func = (zend_function *) orig;
		ZVAL_OBJ(&frame.This, method_obj);
		return proxy_check_type_at_frame(type, arg, orig->common.scope, static_ce, strict, &frame);
	}
	return proxy_check_type(type, arg, orig->common.scope, static_ce, strict);
}

/* Internal scalar parameters still accept null in weak mode. Use the native
 * parsers for the deprecation (including exceptions from an error handler),
 * then pass the coerced value to the interceptor so proceeding does not warn twice. */
static bool proxy_check_arg_type(const zend_function *orig, const zend_arg_info *ai, uint32_t arg_num, zval *arg, zend_object *method_obj, zend_class_entry *static_ce, bool strict)
{
	zval *value = arg;
	ZVAL_DEREF(value);
	uint32_t mask = ZEND_TYPE_FULL_MASK(ai->type);
	if (orig->type == ZEND_INTERNAL_FUNCTION && !strict && Z_TYPE_P(value) == IS_NULL
			&& !(mask & MAY_BE_NULL)
			&& !(Z_ISREF_P(arg) && ZEND_REF_HAS_TYPE_SOURCES(Z_REF_P(arg)))) {
		if (mask & MAY_BE_LONG) {
			zend_long result;
			if (!zend_parse_arg_long_weak(value, &result, arg_num)) {
				return false;
			}
			ZVAL_LONG(value, result);
			return true;
		}
		if (mask & MAY_BE_DOUBLE) {
			double result;
			if (!zend_parse_arg_double_weak(value, &result, arg_num)) {
				return false;
			}
			ZVAL_DOUBLE(value, result);
			return true;
		}
		if (mask & MAY_BE_STRING) {
			zend_string *result;
			return zend_parse_arg_str_weak(value, &result, arg_num);
		}
		if ((mask & MAY_BE_BOOL) == MAY_BE_BOOL) {
			bool result;
			if (!zend_parse_arg_bool_weak(value, &result, arg_num)) {
				return false;
			}
			ZVAL_BOOL(value, result);
			return true;
		}
	}
	return proxy_check_method_type(orig, &ai->type, arg, method_obj, static_ce, strict);
}

static ZEND_COLD void proxy_arg_type_error(const zend_function *orig, const zend_arg_info *ai, uint32_t arg_num, zval *arg)
{
	if (orig->type == ZEND_USER_FUNCTION) {
		zend_verify_arg_error(orig, ai, arg_num, arg);
		return;
	}
	zend_string *type_str = zend_type_to_string(ai->type);
	zend_argument_type_error(arg_num, "must be of type %s, %s given", ZSTR_VAL(type_str), zend_zval_value_name(arg));
	zend_string_release(type_str);
}

/* Default value of parameter i of the proxied method (named argument gaps). */
static zend_result proxy_default_arg(const zend_function *orig, uint32_t i, zval *dst)
{
	if (orig->type == ZEND_USER_FUNCTION) {
		const zend_op *opline = &orig->op_array.opcodes[i];
		if (UNEXPECTED(opline->opcode != ZEND_RECV_INIT)) {
			zend_argument_error(zend_ce_argument_count_error, i + 1, "not passed");
			return FAILURE;
		}
		ZVAL_COPY(dst, RT_CONSTANT(opline, opline->op2));
	} else if (zend_get_default_from_internal_arg_info(dst, &orig->internal_function.arg_info[i]) == FAILURE) {
		zend_argument_error(zend_ce_argument_count_error, i + 1, "must be passed explicitly, because the default value is not known");
		return FAILURE;
	}

	/* Both user and internal defaults may still contain an unevaluated
	 * constant expression. Resolve it in the declaring method's scope. */
	if (Z_TYPE_P(dst) == IS_CONSTANT_AST
			&& UNEXPECTED(zval_update_constant_ex(dst, orig->common.scope) != SUCCESS)) {
		zval_ptr_dtor_nogc(dst);
		ZVAL_UNDEF(dst);
		return FAILURE;
	}
	return SUCCESS;
}

/* Applies the proxied method's parameter contract to the arguments of the
 * trampoline frame: fills skipped optional parameters with their defaults and
 * verifies/coerces argument types using the caller's strict_types mode. */
static zend_result proxy_prepare_frame_args(zend_execute_data *execute_data, proxy_method *pm, zend_class_entry *static_ce, uint32_t argc, zval *args)
{
	const zend_function *orig = pm->original;
	uint32_t num_args = orig->common.num_args;
	bool variadic = (orig->common.fn_flags & ZEND_ACC_VARIADIC) != 0;
	bool strict = ZEND_ARG_USES_STRICT_TYPES();

	if (orig->type == ZEND_INTERNAL_FUNCTION && UNEXPECTED(argc < orig->common.required_num_args || (!variadic && argc > num_args))) {
		zend_wrong_parameters_count_error(orig->common.required_num_args, num_args);
		return FAILURE;
	}
	if (UNEXPECTED(argc < orig->common.required_num_args)) {
		zend_missing_arg_error(execute_data);
		return FAILURE;
	}

	for (uint32_t i = 0; i < argc; i++) {
		zval *arg = &args[i];
		const zend_arg_info *ai = NULL;

		if (i < num_args) {
			ai = &orig->common.arg_info[i];
		} else if (variadic) {
			ai = &orig->common.arg_info[num_args];
		}

		if (UNEXPECTED(Z_ISUNDEF_P(arg))) {
			if (i >= num_args || i < orig->common.required_num_args) {
				zend_argument_error(zend_ce_argument_count_error, i + 1, "not passed");
				return FAILURE;
			}
			if (proxy_default_arg(orig, i, arg) == FAILURE) {
				return FAILURE;
			}
			if (ai && (ZEND_ARG_SEND_MODE(ai) & ZEND_SEND_BY_REF)) {
				ZVAL_NEW_REF(arg, arg);
			}
			continue;
		}

		if (ai && ZEND_TYPE_IS_SET(ai->type) && UNEXPECTED(!proxy_check_arg_type(orig, ai, i + 1, arg, Z_OBJ(execute_data->This), static_ce, strict))) {
			if (!EG(exception)) {
				zval *v = arg;
				ZVAL_DEREF(v);
				proxy_arg_type_error(orig, ai, i + 1, v);
			}
			return FAILURE;
		}
	}
	if (variadic && (ZEND_CALL_INFO(execute_data) & ZEND_CALL_HAS_EXTRA_NAMED_PARAMS)) {
		const zend_arg_info *ai = &orig->common.arg_info[num_args];
		zval *arg;
		uint32_t position = argc;
		ZEND_HASH_FOREACH_VAL(execute_data->extra_named_params, arg) {
			/* named extras follow the positional arguments in the engine's numbering */
			position++;
			if (ZEND_TYPE_IS_SET(ai->type)
			 && !proxy_check_arg_type(orig, ai, position, arg, Z_OBJ(execute_data->This), static_ce, strict)) {
				if (!EG(exception)) {
					ZVAL_DEREF(arg);
					proxy_arg_type_error(orig, ai, position, arg);
				}
				return FAILURE;
			}
		} ZEND_HASH_FOREACH_END();
	}
	return SUCCESS;
}

static zend_result proxy_verify_return(proxy_object *po, proxy_method *pm, zval *result)
{
	const zend_function *orig = pm->original;
	uint32_t fn_flags = orig->common.fn_flags;

	if (fn_flags & ZEND_ACC_HAS_RETURN_TYPE) {
		const zend_arg_info *ri = &orig->common.arg_info[-1];
		uint32_t mask = ZEND_TYPE_PURE_MASK(ri->type);

		if (mask & MAY_BE_NEVER) {
			zend_verify_never_error(orig);
			return FAILURE;
		}
		if (mask & MAY_BE_VOID) {
			zval *v = result;
			ZVAL_DEREF(v);
			if (Z_TYPE_P(v) != IS_NULL) {
				zend_type_error("%s::%s(): Return value must be of type void, %s returned",
					ZSTR_VAL(orig->common.scope->name), ZSTR_VAL(orig->common.function_name), zend_zval_value_name(v));
				return FAILURE;
			}
			zval_ptr_dtor(result);
			ZVAL_NULL(result);
		} else {
			bool strict = (fn_flags & ZEND_ACC_STRICT_TYPES) != 0;
			if (UNEXPECTED(!proxy_check_method_type(orig, &ri->type, result, po->target ? po->target : po->obj, proxy_type_of(po), strict))) {
				if (!EG(exception)) {
					zval *v = result;
					ZVAL_DEREF(v);
					zend_verify_return_error(orig, v);
				}
				return FAILURE;
			}
		}
	}

	/* A by-reference method whose interceptor produced a plain value returns that
	 * value as is: the engine then raises its usual diagnostics at the call
	 * site ("Only variables should be assigned by reference", "Indirect
	 * modification of overloaded element ... has no effect") instead of the
	 * write silently landing in a temporary reference. */
	if (!(fn_flags & ZEND_ACC_RETURN_REFERENCE) && Z_ISREF_P(result)) {
		zend_unwrap_reference(result);
	}
	return SUCCESS;
}

/* ------------------------------------------------------------------------- */
/* Invocation objects                                                        */
/* ------------------------------------------------------------------------- */

static zend_always_inline proxy_invocation *proxy_invocation_from_obj(zend_object *obj)
{
	return (proxy_invocation *) ((char *) obj - XtOffsetOf(proxy_invocation, std));
}

static zend_always_inline proxy_prop_invocation *proxy_prop_invocation_from_obj(zend_object *obj)
{
	return (proxy_prop_invocation *) ((char *) obj - XtOffsetOf(proxy_prop_invocation, std));
}

static zend_object *proxy_invocation_create(zend_class_entry *ce)
{
	proxy_invocation *inv = zend_object_alloc(sizeof(proxy_invocation), ce);
	memset(inv, 0, XtOffsetOf(proxy_invocation, std));
	zend_object_std_init(&inv->std, ce);
	inv->std.handlers = &proxy_invocation_handlers;
	ZVAL_UNDEF(&inv->args);
	ZVAL_UNDEF(&inv->interceptor);
	return &inv->std;
}

/* Releasing a captured argument/value or the proxy can run a destructor.
 * Clear weak observers first so that destructor cannot resurrect this
 * invocation after its object-store reference count has reached zero. */
static void proxy_invocation_clear_weakrefs(zend_object *obj)
{
	if (UNEXPECTED(GC_FLAGS(obj) & IS_OBJ_WEAKLY_REFERENCED)) {
		zend_weakrefs_notify(obj);
		GC_DEL_FLAGS(obj, IS_OBJ_WEAKLY_REFERENCED);
	}
}

static void proxy_invocation_free(zend_object *obj)
{
	proxy_invocation *inv = proxy_invocation_from_obj(obj);
	proxy_invocation_clear_weakrefs(obj);
	if (inv->proxy) {
		OBJ_RELEASE(inv->proxy);
	}
	if (inv->name) {
		zend_string_release(inv->name);
	}
	zval_ptr_dtor(&inv->args);
	zval_ptr_dtor(&inv->interceptor);
	zend_object_std_dtor(obj);
}

static HashTable *proxy_invocation_gc(zend_object *obj, zval **table, int *n)
{
	proxy_invocation *inv = proxy_invocation_from_obj(obj);
	zend_get_gc_buffer *buf = zend_get_gc_buffer_create();
	if (inv->proxy) {
		zend_get_gc_buffer_add_obj(buf, inv->proxy);
	}
	zend_get_gc_buffer_add_zval(buf, &inv->args);
	zend_get_gc_buffer_add_zval(buf, &inv->interceptor);
	zend_get_gc_buffer_use(buf, table, n);
	return NULL;
}

static zend_object *proxy_prop_invocation_create(zend_class_entry *ce)
{
	proxy_prop_invocation *pi = zend_object_alloc(sizeof(proxy_prop_invocation), ce);
	memset(pi, 0, XtOffsetOf(proxy_prop_invocation, std));
	zend_object_std_init(&pi->std, ce);
	pi->std.handlers = &proxy_prop_invocation_handlers;
	ZVAL_UNDEF(&pi->value);
	ZVAL_UNDEF(&pi->read_value);
	ZVAL_UNDEF(&pi->uninitialized_refs);
	ZVAL_UNDEF(&pi->interceptor);
	return &pi->std;
}

static void proxy_prop_invocation_free(zend_object *obj)
{
	proxy_prop_invocation *pi = proxy_prop_invocation_from_obj(obj);
	proxy_invocation_clear_weakrefs(obj);
	if (pi->proxy) {
		OBJ_RELEASE(pi->proxy);
	}
	if (pi->name) {
		zend_string_release(pi->name);
	}
	zval_ptr_dtor(&pi->value);
	zval_ptr_dtor(&pi->read_value);
	zval_ptr_dtor(&pi->uninitialized_refs);
	zval_ptr_dtor(&pi->interceptor);
	zend_object_std_dtor(obj);
}

static HashTable *proxy_prop_invocation_gc(zend_object *obj, zval **table, int *n)
{
	proxy_prop_invocation *pi = proxy_prop_invocation_from_obj(obj);
	zend_get_gc_buffer *buf = zend_get_gc_buffer_create();
	if (pi->proxy) {
		zend_get_gc_buffer_add_obj(buf, pi->proxy);
	}
	zend_get_gc_buffer_add_zval(buf, &pi->value);
	zend_get_gc_buffer_add_zval(buf, &pi->read_value);
	zend_get_gc_buffer_add_zval(buf, &pi->uninitialized_refs);
	zend_get_gc_buffer_add_zval(buf, &pi->interceptor);
	zend_get_gc_buffer_use(buf, table, n);
	return NULL;
}

void proxy_dispatch_minit(void)
{
	memcpy(&proxy_invocation_handlers, &std_object_handlers, sizeof(zend_object_handlers));
	proxy_invocation_handlers.offset = XtOffsetOf(proxy_invocation, std);
	proxy_invocation_handlers.free_obj = proxy_invocation_free;
	proxy_invocation_handlers.get_gc = proxy_invocation_gc;
	proxy_invocation_handlers.clone_obj = NULL;
	proxy_ce_Invocation->create_object = proxy_invocation_create;
	proxy_ce_Invocation->default_object_handlers = &proxy_invocation_handlers;

	memcpy(&proxy_prop_invocation_handlers, &std_object_handlers, sizeof(zend_object_handlers));
	proxy_prop_invocation_handlers.offset = XtOffsetOf(proxy_prop_invocation, std);
	proxy_prop_invocation_handlers.free_obj = proxy_prop_invocation_free;
	proxy_prop_invocation_handlers.get_gc = proxy_prop_invocation_gc;
	proxy_prop_invocation_handlers.clone_obj = NULL;
	proxy_ce_PropertyInvocation->create_object = proxy_prop_invocation_create;
	proxy_ce_PropertyInvocation->default_object_handlers = &proxy_prop_invocation_handlers;
}

/* ------------------------------------------------------------------------- */
/* Calling interceptors and originals                                        */
/* ------------------------------------------------------------------------- */

/* Builds the canonical argument array of a call: positional arguments first
 * (references preserved), then named extras with string keys. */
static void proxy_build_args_array(zval *dst, uint32_t argc, zval *args, HashTable *named)
{
	uint32_t extra = named ? zend_hash_num_elements(named) : 0;
	array_init_size(dst, argc + extra);
	HashTable *ht = Z_ARRVAL_P(dst);
	for (uint32_t i = 0; i < argc; i++) {
		zval tmp;
		ZVAL_COPY(&tmp, &args[i]);
		zend_hash_next_index_insert_new(ht, &tmp);
	}
	if (extra) {
		zend_string *key;
		zval *val;
		ZEND_HASH_FOREACH_STR_KEY_VAL(named, key, val) {
			if (key) {
				Z_TRY_ADDREF_P(val);
				zend_hash_add_new(ht, key, val);
			}
		} ZEND_HASH_FOREACH_END();
	}
}

/* Every interceptor receives only its invocation object. Keeping call
 * arguments in args() avoids collisions with the callback's parameter name. */
static void proxy_call_interceptor(zval *callable, zval *invocation, zval *retval)
{
	zend_fcall_info fci;
	zend_fcall_info_cache fcc;
	char *error = NULL;

	ZVAL_UNDEF(retval);
	if (zend_fcall_info_init(callable, 0, &fci, &fcc, NULL, &error) == FAILURE) {
		proxy_throw(proxy_ce_InvalidInterceptor, "Interceptor is not callable: %s", error ? error : "unknown reason");
		if (error) {
			efree(error);
		}
		return;
	}
	if (error) {
		efree(error);
	}

	fci.retval = retval;
	fci.params = invocation;
	fci.param_count = 1;
	fci.named_params = NULL;
	zend_call_function(&fci, &fcc);
	if (EG(exception)) {
		zval_ptr_dtor(retval);
		ZVAL_UNDEF(retval);
	}
}

static void proxy_call_original(proxy_invocation *inv, HashTable *args, zval *retval)
{
	proxy_object *po = inv->po;
	zend_function *original = NULL;

	ZVAL_UNDEF(retval);
	if (po->target) {
		if (inv->pm && inv->pm->has_body) {
			original = inv->pm->original;
		} else if (!inv->pm && po->cls->has_call) {
			/* The engine already applied the call form's reference rules.
			 * call_user_func_array(), in particular, may preserve aliases. */
			original = zend_get_call_trampoline_func(po->target->ce, inv->name, 0);
		}
	}

	if (!original) {
		proxy_throw(proxy_ce_UnconfiguredMethod, "Call to unconfigured method %s::%s() on mock",
			ZSTR_VAL(proxy_type_of(po)->name), ZSTR_VAL(inv->name));
		return;
	}

	zend_call_known_function(original, po->target, po->target->ce, retval, 0, NULL, args);
	if (EG(exception)) {
		zval_ptr_dtor(retval);
		ZVAL_UNDEF(retval);
	}
}

/* A nested proceed() restores the active interceptor's snapshot. An escaped
 * invocation instead retains the latest arguments/value. Restore the visible
 * state before releasing a value: its destructor can call this invocation.
 * The caller has already restored the invocation's running flag. */
static void proxy_restore_invocation_value(zval *current, zval *saved, bool nested)
{
	if (nested) {
		zval completed;
		ZVAL_COPY_VALUE(&completed, current);
		ZVAL_COPY_VALUE(current, saved);
		zval_ptr_dtor(&completed);
	} else {
		zval_ptr_dtor(saved);
	}
}

/* Runs either the interceptor or the target with the given (owned) argument
 * array. Nested proceed() calls restore the caller's captured arguments;
 * a retained invocation records the arguments of its latest completed call. */
static void proxy_run_method(proxy_invocation *inv, bool intercept, zval *args, zval *result)
{
	zval saved_args;
	bool nested = inv->running;

	ZVAL_COPY_VALUE(&saved_args, &inv->args);
	ZVAL_COPY_VALUE(&inv->args, args);
	inv->running = true;
	ZVAL_UNDEF(result);

	if (intercept && !Z_ISUNDEF(inv->interceptor)) {
		zval inv_zv;
		ZVAL_OBJ(&inv_zv, &inv->std);
		proxy_call_interceptor(&inv->interceptor, &inv_zv, result);
	} else {
		proxy_call_original(inv, Z_ARRVAL(inv->args), result);
	}

	inv->running = nested;
	proxy_restore_invocation_value(&inv->args, &saved_args, nested);
}

/* Full dispatch of one method call. Takes ownership of the argument array. */
static void proxy_invoke(proxy_object *po, zend_object *proxy_obj, proxy_method *pm, zend_string *name, zval *args, zval *retval)
{
	proxy_invocation *inv = proxy_invocation_from_obj(proxy_invocation_create(proxy_ce_Invocation));

	GC_ADDREF(proxy_obj);
	inv->proxy = proxy_obj;
	inv->po = po;
	inv->pm = pm;
	inv->name = zend_string_copy(name);
	if (!Z_ISUNDEF(po->method_interceptor)) {
		ZVAL_COPY(&inv->interceptor, &po->method_interceptor);
	}

	zval result;
	proxy_run_method(inv, true, args, &result);
	if (!Z_ISUNDEF(result)) {
		if (pm) {
			if (proxy_verify_return(po, pm, &result) == FAILURE) {
				zval_ptr_dtor(&result);
				ZVAL_UNDEF(&result);
			}
		} else if (Z_ISREF(result)
				&& !(po->cls->has_call && (po->cls->type_ce->__call->common.fn_flags & ZEND_ACC_RETURN_REFERENCE))) {
			zend_unwrap_reference(&result);
		}
	}
	ZVAL_COPY_VALUE(retval, &result);
	OBJ_RELEASE(&inv->std);
}

void proxy_dispatch_method(proxy_object *po, zend_object *proxy_obj, proxy_method *pm, uint32_t argc, zval *args, zval *retval)
{
	zval arr;
	proxy_build_args_array(&arr, argc, args, NULL);
	proxy_invoke(po, proxy_obj, pm, pm->fn.function_name, &arr, retval);
}

/* ------------------------------------------------------------------------- */
/* Trampoline handlers                                                       */
/* ------------------------------------------------------------------------- */

static proxy_object *proxy_handler_this(zend_execute_data *execute_data, zend_object **this_out)
{
	zend_object *this_obj = Z_TYPE(EX(This)) == IS_OBJECT ? Z_OBJ(EX(This)) : NULL;
	proxy_object *po = NULL;

	if (this_obj && proxy_is_proxy(this_obj)) {
		po = proxy_object_from_obj(this_obj);
		if (!po || !po->initialized) {
			/* an object of the generated class without proxy state (allocated
			 * by the engine, or refused by proxy_create_object()) */
			proxy_throw(proxy_ce_UnsupportedOperation, "Proxy object of class %s was not created through Proxy\\Proxy::mock() or Proxy\\Proxy::wrap()", ZSTR_VAL(this_obj->ce->name));
			po = NULL;
		} else if (UNEXPECTED(zend_object_is_lazy(this_obj)) && proxy_refuse_lazy(this_obj)) {
			po = NULL;
		}
	} else if (this_obj && this_obj->ce->create_object == proxy_create_object) {
		proxy_throw(proxy_ce_UnsupportedOperation, "Proxy object of class %s was not created through Proxy\\Proxy::mock() or Proxy\\Proxy::wrap()", ZSTR_VAL(this_obj->ce->name));
	} else {
		zend_throw_error(NULL, "Proxy method %s() must be called on a proxy object", ZSTR_VAL(EX(func)->common.function_name));
	}
	*this_out = this_obj;
	return po;
}

/* A first-class callable or Closure::fromCallable() of a name that resolves
 * to __call() is a fake closure of a magic-call trampoline. The engine turns
 * it into a call of __call($name, $args) on its scope, which is the generated
 * class. Recognize that frame and dispatch under the method's own name. */
static bool proxy_magic_closure_call(zend_execute_data *execute_data, proxy_method *pm, zend_object *this_obj, proxy_object *po, zval *return_value)
{
	zend_execute_data *frame = execute_data->prev_execute_data;
	if (!pm->cls->has_call || pm->cls->ce->__call != (zend_function *) &pm->fn || !frame || !frame->func
			|| frame->func->type != ZEND_INTERNAL_FUNCTION
			|| !(frame->func->common.fn_flags & ZEND_ACC_CLOSURE)
			|| frame->func->common.scope != pm->cls->ce
			|| Z_TYPE(frame->This) != IS_OBJECT || Z_OBJ(frame->This) != this_obj
			|| ZEND_CALL_NUM_ARGS(execute_data) != 2) {
		return false;
	}
	zval *name = ZEND_CALL_ARG(execute_data, 1);
	zval *args = ZEND_CALL_ARG(execute_data, 2);
	if (Z_TYPE_P(name) != IS_STRING || Z_TYPE_P(args) != IS_ARRAY
			|| !zend_string_equals(Z_STR_P(name), frame->func->common.function_name)) {
		return false;
	}
	zval arr, result;
	ZVAL_COPY(&arr, args);
	proxy_invoke(po, this_obj, NULL, Z_STR_P(name), &arr, &result);
	if (!Z_ISUNDEF(result)) {
		ZVAL_COPY_VALUE(return_value, &result);
	}
	return true;
}

void ZEND_FASTCALL proxy_method_handler(INTERNAL_FUNCTION_PARAMETERS)
{
	proxy_method *pm = EX(func)->internal_function.reserved[proxy_reserved_slot];
	zend_object *this_obj;
	proxy_object *po = proxy_handler_this(execute_data, &this_obj);

	if (po && proxy_magic_closure_call(execute_data, pm, this_obj, po, return_value)) {
		po = NULL;
	}
	if (po) {
		uint32_t argc = ZEND_CALL_NUM_ARGS(execute_data);
		zval *args = ZEND_CALL_ARG(execute_data, 1);
		HashTable *extra = (ZEND_CALL_INFO(execute_data) & ZEND_CALL_HAS_EXTRA_NAMED_PARAMS) ? EX(extra_named_params) : NULL;

		if (proxy_prepare_frame_args(execute_data, pm, proxy_type_of(po), argc, args) == SUCCESS) {
			zval arr, result;
			proxy_build_args_array(&arr, argc, args, extra);
			proxy_invoke(po, this_obj, pm, pm->fn.function_name, &arr, &result);
			if (!Z_ISUNDEF(result)) {
				ZVAL_COPY_VALUE(return_value, &result);
			}
		}
	}

#if ZEND_DEBUG
	/* The debug-mode consistency checks that follow an internal call assume
	 * a hand-written internal function; this trampoline mirrors a userland
	 * signature (weak-mode coercion, extra arguments, ...). Same technique as
	 * Closure::__invoke(). */
	execute_data->func = NULL;
#endif
}

void ZEND_FASTCALL proxy_dynamic_method_handler(INTERNAL_FUNCTION_PARAMETERS)
{
	zend_function *fn = EX(func);
	zend_string *name = fn->common.function_name;
	zend_object *this_obj;
	proxy_object *po = proxy_handler_this(execute_data, &this_obj);

	if (po) {
		uint32_t argc = ZEND_CALL_NUM_ARGS(execute_data);
		zval *args = ZEND_CALL_ARG(execute_data, 1);
		HashTable *extra = (ZEND_CALL_INFO(execute_data) & ZEND_CALL_HAS_EXTRA_NAMED_PARAMS) ? EX(extra_named_params) : NULL;
		zval arr, result;
		proxy_build_args_array(&arr, argc, args, extra);
		proxy_invoke(po, this_obj, NULL, name, &arr, &result);
		if (!Z_ISUNDEF(result)) {
			ZVAL_COPY_VALUE(return_value, &result);
		}
	}

	/* This trampoline was allocated by get_method(); release it like Closure::__invoke() does. */
	zend_string_release_ex(name, 0);
	zend_free_trampoline(fn);
	execute_data->func = NULL;
}

/* ------------------------------------------------------------------------- */
/* Proxy\Invocation                                                          */
/* ------------------------------------------------------------------------- */

#define PROXY_INVOCATION_THIS() proxy_invocation_from_obj(Z_OBJ_P(ZEND_THIS))

ZEND_METHOD(Proxy_Invocation, __construct)
{
	ZEND_PARSE_PARAMETERS_NONE();
}

ZEND_METHOD(Proxy_Invocation, method)
{
	ZEND_PARSE_PARAMETERS_NONE();
	proxy_invocation *inv = PROXY_INVOCATION_THIS();
	RETURN_STR_COPY(inv->name);
}

ZEND_METHOD(Proxy_Invocation, args)
{
	ZEND_PARSE_PARAMETERS_NONE();
	proxy_invocation *inv = PROXY_INVOCATION_THIS();
	if (Z_ISUNDEF(inv->args)) {
		RETURN_EMPTY_ARRAY();
	}
	RETURN_COPY(&inv->args);
}

ZEND_METHOD(Proxy_Invocation, arg)
{
	zend_long index;
	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_LONG(index)
	ZEND_PARSE_PARAMETERS_END();

	proxy_invocation *inv = PROXY_INVOCATION_THIS();
	zval *zv = Z_ISUNDEF(inv->args) ? NULL : zend_hash_index_find(Z_ARRVAL(inv->args), index);
	if (!zv) {
		zend_argument_value_error(1, "must be a valid argument index");
		RETURN_THROWS();
	}
	RETURN_COPY_DEREF(zv);
}

ZEND_METHOD(Proxy_Invocation, proxy)
{
	ZEND_PARSE_PARAMETERS_NONE();
	proxy_invocation *inv = PROXY_INVOCATION_THIS();
	RETURN_OBJ_COPY(inv->proxy);
}

ZEND_METHOD(Proxy_Invocation, target)
{
	ZEND_PARSE_PARAMETERS_NONE();
	proxy_invocation *inv = PROXY_INVOCATION_THIS();
	if (inv->po->target) {
		RETURN_OBJ_COPY(inv->po->target);
	}
	RETURN_NULL();
}

ZEND_METHOD(Proxy_Invocation, class)
{
	ZEND_PARSE_PARAMETERS_NONE();
	proxy_invocation *inv = PROXY_INVOCATION_THIS();
	RETURN_STR_COPY(proxy_type_of(inv->po)->name);
}

ZEND_METHOD(Proxy_Invocation, hasOriginal)
{
	ZEND_PARSE_PARAMETERS_NONE();
	proxy_invocation *inv = PROXY_INVOCATION_THIS();
	proxy_object *po = inv->po;
	RETURN_BOOL(po->target && (inv->pm ? inv->pm->has_body : po->cls->has_call));
}

ZEND_METHOD(Proxy_Invocation, proceed)
{
	zval *args;
	uint32_t argc;
	HashTable *named = NULL;

	ZEND_PARSE_PARAMETERS_START(0, -1)
		Z_PARAM_VARIADIC_WITH_NAMED(args, argc, named)
	ZEND_PARSE_PARAMETERS_END();

	proxy_invocation *inv = PROXY_INVOCATION_THIS();
	zval arr, result;
	proxy_build_args_array(&arr, argc, args, named);
	proxy_run_method(inv, false, &arr, &result);
	if (Z_ISUNDEF(result)) {
		if (EG(exception)) {
			RETURN_THROWS();
		}
		ZVAL_NULL(&result);
	}
	/* proceed() returns by reference so that reference-returning methods stay intact */
	if (!Z_ISREF(result)) {
		ZVAL_NEW_REF(&result, &result);
	}
	ZVAL_COPY_VALUE(return_value, &result);
}

/* ------------------------------------------------------------------------- */
/* Property dispatch                                                         */
/* ------------------------------------------------------------------------- */

bool proxy_has_property_interceptor(const proxy_object *po)
{
	return !Z_ISUNDEF(po->property_interceptor);
}

typedef enum {
	PROXY_PROPERTY_ALLOWED,
	PROXY_PROPERTY_SILENT, /* inaccessible isset(), or unset() with no writable slot */
	PROXY_PROPERTY_ERROR,
} proxy_property_access;

/* Apply normal PHP resolution before invoking user code. A silent result
 * must also skip interception; PHP has already determined the operation's
 * result without handing out a readable or writable property. */
static proxy_property_access proxy_property_check(proxy_object *po, int op, zend_string *name, int mode, zend_class_entry *scope, zend_property_info **info_out)
{
	zend_class_entry *type_ce = proxy_type_of(po);
	const zend_class_entry *old_scope = EG(fake_scope);
	zend_property_info *info;

	EG(fake_scope) = (zend_class_entry *) scope;
	info = zend_get_property_info(type_ce, name, /* silent */ 1);
	EG(fake_scope) = (zend_class_entry *) old_scope;
	*info_out = NULL;

	if (info == ZEND_WRONG_PROPERTY_INFO) {
		bool magic = (op == PROXY_PROP_GET && type_ce->__get)
			|| (op == PROXY_PROP_SET && type_ce->__set)
			|| (op == PROXY_PROP_ISSET && type_ce->__isset)
			|| (op == PROXY_PROP_UNSET && type_ce->__unset);
		if (magic) {
			return PROXY_PROPERTY_ALLOWED;
		}
		if (op == PROXY_PROP_ISSET) {
			return PROXY_PROPERTY_SILENT;
		}
		/* reproduce the engine's error */
		EG(fake_scope) = (zend_class_entry *) scope;
		zend_get_property_info(type_ce, name, 0);
		EG(fake_scope) = (zend_class_entry *) old_scope;
		if (!EG(exception)) {
			zend_throw_error(NULL, "Cannot access property %s::$%s", ZSTR_VAL(type_ce->name), ZSTR_VAL(name));
		}
		return PROXY_PROPERTY_ERROR;
	}

	if (info == NULL) {
		/* undeclared property */
		if (op == PROXY_PROP_SET && (type_ce->ce_flags & ZEND_ACC_NO_DYNAMIC_PROPERTIES) && !type_ce->__set) {
			zend_throw_error(NULL, "Cannot create dynamic property %s::$%s", ZSTR_VAL(type_ce->name), ZSTR_VAL(name));
			return PROXY_PROPERTY_ERROR;
		}
		return PROXY_PROPERTY_ALLOWED;
	}
	if (info->hooks) {
		if (op == PROXY_PROP_UNSET) {
			zend_throw_error(NULL, "Cannot unset hooked property %s::$%s", ZSTR_VAL(type_ce->name), ZSTR_VAL(name));
			return PROXY_PROPERTY_ERROR;
		}
		if (info->flags & ZEND_ACC_VIRTUAL) {
			if ((op == PROXY_PROP_GET || (op == PROXY_PROP_ISSET && mode != ZEND_PROPERTY_EXISTS))
			 && !info->hooks[ZEND_PROPERTY_HOOK_GET]) {
				zend_throw_error(NULL, "Property %s::$%s is write-only", ZSTR_VAL(type_ce->name), ZSTR_VAL(name));
				return PROXY_PROPERTY_ERROR;
			}
			if (op == PROXY_PROP_SET && !info->hooks[ZEND_PROPERTY_HOOK_SET]) {
				zend_throw_error(NULL, "Property %s::$%s is read-only", ZSTR_VAL(type_ce->name), ZSTR_VAL(name));
				return PROXY_PROPERTY_ERROR;
			}
		}
	}
	bool modifies = op == PROXY_PROP_SET || op == PROXY_PROP_UNSET;
	/* a writable fetch ($r = &$p->x, $p->x[] = 1, unset($p->x[0])) of a backed property */
	bool indirect = op == PROXY_PROP_GET && !info->hooks
		&& (mode == BP_VAR_W || mode == BP_VAR_RW || mode == BP_VAR_UNSET);
	/* An uninitialized lazy ghost, or a lazy proxy in any state: PHP inspects
	 * the wrapper's own slot first, which is uninitialized, and reaches the
	 * real instance only after initialization. */
	bool lazy_wrapper = po->target && zend_object_is_lazy(po->target);
	/* The operation may run while an exception is pending (++ writes back
	 * after a failed increment); only a new one aborts the check. */
	zend_object *pending = EG(exception);

	if (modifies && !lazy_wrapper && po->target && !info->hooks && !(info->flags & ZEND_ACC_STATIC)
	 && (op == PROXY_PROP_SET ? type_ce->__set : type_ce->__unset) != NULL) {
		zval *slot = OBJ_PROP(po->target, info->offset);
		if (Z_TYPE_P(slot) == IS_UNDEF && !(Z_PROP_FLAG_P(slot) & IS_PROP_UNINIT)) {
			/* an explicitly unset slot of a class with __set()/__unset(): PHP
			 * dispatches to the magic method without visibility, readonly or
			 * type checks (no property info: the value is not coerced) */
			return PROXY_PROPERTY_ALLOWED;
		}
	}

	if ((modifies || indirect) && !(info->flags & ZEND_ACC_STATIC)
	 && (info->flags & (ZEND_ACC_READONLY | ZEND_ACC_PPP_SET_MASK))) {
		/* Same decisions as zend_std_write_property()/unset_property()/
		 * read_property()/get_property_ptr_ptr(), in the same order. */
		EG(fake_scope) = (zend_class_entry *) scope;
		bool allowed = !(info->flags & ZEND_ACC_PPP_SET_MASK) || zend_asymmetric_property_has_set_access(info);
		EG(fake_scope) = (zend_class_entry *) old_scope;
		bool readonly = (info->flags & ZEND_ACC_READONLY) != 0;

		if (lazy_wrapper || !po->target) {
			if (modifies && !allowed) {
				/* decided on the uninitialized slot, before initialization */
				zend_asymmetric_visibility_property_modification_error(info, op == PROXY_PROP_SET ? "modify" : "unset");
				return PROXY_PROPERTY_ERROR;
			}
			if (indirect && (readonly || !allowed)) {
				/* PHP initializes the lazy object while resolving the slot,
				 * then refuses the wrapper's uninitialized slot: silently for
				 * unset(), with the indirect-modification error otherwise */
				if (lazy_wrapper) {
					proxy_storage_object(po, true);
					if (EG(exception) != pending) {
						return PROXY_PROPERTY_ERROR;
					}
					if (mode == BP_VAR_UNSET) {
						return PROXY_PROPERTY_SILENT;
					}
				}
				if (readonly) {
					zend_readonly_property_indirect_modification_error(info);
				} else {
					zend_asymmetric_visibility_property_modification_error(info, "indirectly modify");
				}
				return PROXY_PROPERTY_ERROR;
			}
		}

		zval *slot = NULL;
		if (po->target && !(info->flags & ZEND_ACC_VIRTUAL)) {
			zend_object *storage = proxy_storage_object(po, true);
			if (EG(exception) != pending) {
				return PROXY_PROPERTY_ERROR;
			}
			if (storage) {
				slot = OBJ_PROP(storage, info->offset);
			}
		}

		if (modifies) {
			bool magic = lazy_wrapper && slot && Z_TYPE_P(slot) == IS_UNDEF && !(Z_PROP_FLAG_P(slot) & IS_PROP_UNINIT)
				&& (op == PROXY_PROP_SET ? type_ce->__set : type_ce->__unset) != NULL;
			if (magic) {
				/* the real instance's explicitly unset slot: its magic method */
				return PROXY_PROPERTY_ALLOWED;
			}
			if (readonly && slot && Z_TYPE_P(slot) != IS_UNDEF && !(Z_PROP_FLAG_P(slot) & IS_PROP_REINITABLE)) {
				if (op == PROXY_PROP_SET) {
					zend_readonly_property_modification_error(info);
				} else {
					/* zend_readonly_property_unset_error() is not exported */
					zend_throw_error(NULL, "Cannot unset readonly property %s::$%s", ZSTR_VAL(info->ce->name), ZSTR_VAL(name));
				}
				return PROXY_PROPERTY_ERROR;
			}
			if (!allowed) {
				zend_asymmetric_visibility_property_modification_error(info, op == PROXY_PROP_SET ? "modify" : "unset");
				return PROXY_PROPERTY_ERROR;
			}
		} else if (readonly || !allowed) {
			if (slot && Z_TYPE_P(slot) == IS_OBJECT) {
				/* objects are handed out as copies; the fetch cannot modify the slot */
			} else if (slot && Z_TYPE_P(slot) == IS_UNDEF && mode == BP_VAR_UNSET) {
				/* nothing to unset */
			} else if (readonly) {
				zend_readonly_property_indirect_modification_error(info);
				return PROXY_PROPERTY_ERROR;
			} else {
				zend_asymmetric_visibility_property_modification_error(info, "indirectly modify");
				return PROXY_PROPERTY_ERROR;
			}
		}
	}
	if (!(info->flags & ZEND_ACC_STATIC)) {
		*info_out = info;
	}
	return PROXY_PROPERTY_ALLOWED;
}

/* Declared property types are part of the proxied type's contract: values
 * produced by interceptors (GET results, SET values) are verified and, in
 * weak mode, coerced exactly like a property assignment would be. */
static bool proxy_verify_prop_type(const zend_property_info *info, zval *value)
{
	if (!info || !ZEND_TYPE_IS_SET(info->type)) {
		return true;
	}
	zval *v = value;
	ZVAL_DEREF(v);
	bool strict = EG(current_execute_data) && EG(current_execute_data)->func
		&& ZEND_CALL_USES_STRICT_TYPES(EG(current_execute_data));
	/* A reference returned by middleware can belong to another typed property.
	 * Native reference returns reject conversions in this case: coercing the
	 * shared value directly could invalidate that property's own type. */
	bool valid = Z_ISREF_P(value) && ZEND_REF_HAS_TYPE_SOURCES(Z_REF_P(value))
		? proxy_check_type(&info->type, value, info->ce, info->ce, strict)
		: zend_verify_property_type(info, v, strict);
	if (valid) {
		return true;
	}
	if (!EG(exception)) {
		zend_string *type_str = zend_type_to_string(info->type);
		zend_type_error("Cannot assign %s to property %s::$%s of type %s",
			zend_zval_value_name(v), ZSTR_VAL(info->ce->name),
			zend_get_unmangled_property_name(info->name), ZSTR_VAL(type_str));
		zend_string_release(type_str);
	}
	return false;
}

/* zend_type_to_string() with `self`/`parent` resolved against the declaring
 * scope, like the engine's error messages (PHP < 8.5 keeps them unresolved
 * in hook signatures). The engine's own helper is not exported. */
static zend_string *proxy_type_to_string_resolved(zend_type type, zend_class_entry *scope)
{
	zend_string *str = zend_type_to_string(type);
	if (!scope || (!strstr(ZSTR_VAL(str), "self") && !strstr(ZSTR_VAL(str), "parent"))) {
		return str;
	}
	smart_str out = {0};
	const char *s = ZSTR_VAL(str), *end = s + ZSTR_LEN(str);
	while (s < end) {
		const char *tok = s;
		while (s < end && (isalnum((unsigned char) *s) || *s == '_' || *s == '\\')) {
			s++;
		}
		if (s == tok) {
			smart_str_appendc(&out, *s++);
			continue;
		}
		size_t len = s - tok;
		if (len == 4 && zend_binary_strcasecmp(tok, 4, "self", 4) == 0) {
			smart_str_append(&out, scope->name);
		} else if (len == 6 && zend_binary_strcasecmp(tok, 6, "parent", 6) == 0 && scope->parent) {
			smart_str_append(&out, scope->parent->name);
		} else {
			smart_str_appendl(&out, tok, len);
		}
	}
	smart_str_0(&out);
	zend_string_release(str);
	return out.s ? out.s : ZSTR_EMPTY_ALLOC();
}

/* A setter may accept a wider type than the property's readable/storage type.
 * Its input is checked like the hook's own parameter, with the hook's
 * argument error. */
static bool proxy_verify_prop_write_type(const zend_property_info *info, zval *value)
{
	if (info && info->hooks && info->hooks[ZEND_PROPERTY_HOOK_SET]) {
		zend_function *set = info->hooks[ZEND_PROPERTY_HOOK_SET];
		const zend_arg_info *ai = &set->common.arg_info[0];
		if (!ZEND_TYPE_IS_SET(ai->type)) {
			return true;
		}
		zval *v = value;
		ZVAL_DEREF(v);
		bool strict = EG(current_execute_data) && EG(current_execute_data)->func
			&& ZEND_CALL_USES_STRICT_TYPES(EG(current_execute_data));
		if (proxy_check_type(&ai->type, value, set->common.scope, set->common.scope, strict)) {
			return true;
		}
		if (!EG(exception)) {
			zend_string *fname = get_function_or_method_name(set);
			zend_string *type_str = proxy_type_to_string_resolved(ai->type, set->common.scope);
			zend_execute_data *caller = EG(current_execute_data);
			if (caller && caller->func && ZEND_USER_CODE(caller->func->type) && caller->opline) {
				zend_type_error("%s(): Argument #1 ($%s) must be of type %s, %s given, called in %s on line %d",
					ZSTR_VAL(fname), ZSTR_VAL(ai->name), ZSTR_VAL(type_str), zend_zval_value_name(v),
					ZSTR_VAL(caller->func->op_array.filename), caller->opline->lineno);
			} else {
				zend_type_error("%s(): Argument #1 ($%s) must be of type %s, %s given",
					ZSTR_VAL(fname), ZSTR_VAL(ai->name), ZSTR_VAL(type_str), zend_zval_value_name(v));
			}
			zend_string_release(type_str);
			zend_string_release(fname);
		}
		return false;
	}
	return proxy_verify_prop_type(info, value);
}

static bool proxy_initialize_writable_property(proxy_prop_invocation *pi, zval *slot)
{
	const zend_property_info *info = pi->info;
	if (!info || info->hooks || !ZEND_TYPE_IS_SET(info->type)
			|| pi->mode != BP_VAR_W || Z_TYPE_P(slot) != IS_UNDEF) {
		return true;
	}
	if (pi->fetch_flags == ZEND_FETCH_REF) {
		if (!ZEND_TYPE_ALLOW_NULL(info->type)) {
			zend_throw_error(NULL, "Cannot access uninitialized non-nullable property %s::$%s by reference",
				ZSTR_VAL(info->ce->name), zend_get_unmangled_property_name(info->name));
			return false;
		}
		ZVAL_NULL(slot);
	} else if (pi->fetch_flags == ZEND_FETCH_DIM_WRITE) {
		if (!(ZEND_TYPE_FULL_MASK(info->type) & MAY_BE_ARRAY)) {
			zend_string *type = zend_type_to_string(info->type);
			zend_type_error("Cannot auto-initialize an array inside property %s::$%s of type %s",
				ZSTR_VAL(info->ce->name), zend_get_unmangled_property_name(info->name), ZSTR_VAL(type));
			zend_string_release(type);
			return false;
		}
		array_init(slot);
	}
	/* Other writable fetches, including nested object writes, must leave the
	 * slot uninitialized so the following opcode retains its native behavior. */
	return true;
}

/* Is the property served by the magic methods: undeclared (and not a
 * dynamic property of the target), or a declared slot the class unset()? */
static bool proxy_prop_is_magic(proxy_object *po, const zend_property_info *info, zend_string *name)
{
	zend_object *storage = proxy_storage_object(po, false);
	if (!info) {
		return !storage || !storage->properties || !zend_hash_find(storage->properties, name);
	}
	if (info->hooks || (info->flags & (ZEND_ACC_STATIC | ZEND_ACC_VIRTUAL)) || !storage) {
		return false;
	}
	zval *slot = OBJ_PROP(storage, info->offset);
	return Z_TYPE_P(slot) == IS_UNDEF && !(Z_PROP_FLAG_P(slot) & IS_PROP_UNINIT);
}

/* The caller holds the target alive and installs the original access scope.
 * It also restores that scope and releases the target if reading throws. */
static void proxy_read_original_property(proxy_prop_invocation *pi, zend_object *target, zval *result)
{
	proxy_object *po = pi->po;
	if (!Z_ISUNDEF(pi->read_value)) {
		/* empty() already evaluated this hook during its ISSET phase.
		 * Consume the saved value once; retries still perform fresh reads. */
		ZVAL_COPY_VALUE(result, &pi->read_value);
		ZVAL_UNDEF(&pi->read_value);
		return;
	}
	if (pi->empty_phase && target->ce->__isset && !target->ce->__get
			&& proxy_prop_is_magic(po, pi->info, pi->name)) {
		/* empty() on a property served by __isset() of a class without
		 * __get(): PHP's isset handler reports "empty" after the
		 * positive __isset() without a second magic call */
		pi->original_ran = true;
		ZVAL_NULL(result);
		return;
	}

	zval temporary_result;
	zval *property_value = NULL;
	bool writable_fetch = pi->mode == BP_VAR_W || pi->mode == BP_VAR_RW || pi->mode == BP_VAR_UNSET;
	if (writable_fetch) {
		/* A fetch for write acquires the storage first, like ZEND_FETCH_OBJ_W. */
		property_value = target->handlers->get_property_ptr_ptr(target, pi->name, pi->mode, NULL);
		if (property_value && Z_ISERROR_P(property_value)) {
			property_value = NULL;
		}
		if (property_value && !EG(exception) && !proxy_initialize_writable_property(pi, property_value)) {
			property_value = NULL;
		}
		if (property_value && !EG(exception) && Z_TYPE_P(property_value) != IS_UNDEF && !Z_ISREF_P(property_value)) {
			/* Expose the actual writable slot through &proceed(), including
			 * mutations made inside the callback before it returns. A hook
			 * instead supplies its own reference via read_property below. */
			ZVAL_MAKE_REF(property_value);
			if (pi->info && !pi->info->hooks && ZEND_TYPE_IS_SET(pi->info->type)) {
				ZEND_REF_ADD_TYPE_SOURCE(Z_REF_P(property_value), pi->info);
			}
		}
	}
	bool used_read_handler = false;
	if (!property_value && !EG(exception)) {
		property_value = target->handlers->read_property(target, pi->name, pi->mode, NULL, &temporary_result);
		used_read_handler = true;
		pi->rv_error = property_value == &temporary_result && EG(exception) && pi->mode == BP_VAR_W;
	}
	pi->original_ran = true;
	if (property_value && used_read_handler && writable_fetch
			&& !Z_ISREF_P(property_value) && Z_TYPE_P(property_value) != IS_OBJECT
			&& Z_TYPE_P(property_value) != IS_UNDEF && property_value != &EG(uninitialized_zval)) {
		/* zend_std_read_property() raised "Indirect modification of
		 * overloaded property" for this __get() result */
		pi->target_notice = true;
	}
	if (!property_value) {
		return;
	}

	/* An uninitialized slot, or PHP's silent placeholder for a
	 * writable fetch it refuses to hand out (readonly and
	 * asymmetric properties that are uninitialized): the VM treats
	 * both as "nothing to modify". */
	pi->writable_unset = (pi->mode == BP_VAR_W || pi->mode == BP_VAR_UNSET)
		&& (Z_TYPE_P(property_value) == IS_UNDEF
			|| (property_value == &EG(uninitialized_zval) && pi->mode == BP_VAR_UNSET));
	if (pi->mode == BP_VAR_IS && property_value == &EG(uninitialized_zval)) {
		pi->conditional_unset = true;
	}
	/* read_property() either fills our owned temporary or lends a target
	 * slot. Move the temporary; retain a borrowed slot and its reference. */
	if (property_value == &temporary_result) {
		ZVAL_COPY_VALUE(result, property_value);
	} else {
		ZVAL_COPY(result, property_value);
	}
	if (pi->writable_unset) {
		/* PHP cannot expose UNDEF to middleware. Give each original
		 * writable fetch its own null reference and retain its identity:
		 * only a callback forwarding that reference can select the
		 * uninitialized slot again. An equal null value is a replacement. */
		ZVAL_NULL(result);
		ZVAL_NEW_REF(result, result);
		if (pi->tracking_uninitialized_refs) {
			if (Z_ISUNDEF(pi->uninitialized_refs)) {
				array_init(&pi->uninitialized_refs);
			}
			Z_TRY_ADDREF_P(result);
			zend_hash_next_index_insert(Z_ARRVAL(pi->uninitialized_refs), result);
		}
	}
}

/* empty() performs ISSET and then GET. Save a native hook's first read so
 * the GET interceptor observes it without executing the hook twice. */
static void proxy_isset_original_property(proxy_prop_invocation *pi, zend_object *target, zval *result)
{
	pi->original_ran = true;
	if (pi->mode == ZEND_PROPERTY_NOT_EMPTY && pi->info && pi->info->hooks
	 && pi->info->hooks[ZEND_PROPERTY_HOOK_GET]) {
		zval rv;
		zval *v = target->handlers->read_property(target, pi->name, BP_VAR_IS, NULL, &rv);
		zval previous_read;
		ZVAL_COPY_VALUE(&previous_read, &pi->read_value);
		ZVAL_UNDEF(&pi->read_value);
		if (!EG(exception)) {
			if (v == &rv) {
				ZVAL_COPY_VALUE(&pi->read_value, v);
			} else {
				ZVAL_COPY(&pi->read_value, v);
			}
			ZVAL_DEREF(v);
			ZVAL_BOOL(result, Z_TYPE_P(v) != IS_NULL);
		} else if (v == &rv) {
			zval_ptr_dtor(&rv);
		}
		/* Publish the replacement before a previous value's destructor can
		 * re-enter proceed() and inspect the same invocation. */
		zval_ptr_dtor(&previous_read);
		return;
	}
	int mode = pi->mode == ZEND_PROPERTY_NOT_EMPTY ? ZEND_PROPERTY_ISSET : pi->mode;
	ZVAL_BOOL(result, target->handlers->has_property(target, pi->name, mode, NULL));
}

static void proxy_prop_original(proxy_prop_invocation *pi, zval *result)
{
	proxy_object *po = pi->po;

	ZVAL_UNDEF(result);
	if (!po->target) {
		proxy_throw(proxy_ce_UnconfiguredProperty, "Unconfigured %s of property %s::$%s on mock",
			proxy_prop_op_names[pi->op], ZSTR_VAL(proxy_type_of(po)->name), ZSTR_VAL(pi->name));
		return;
	}

	zend_object *target = po->target;
	const zend_class_entry *old_scope = EG(fake_scope);
	EG(fake_scope) = (zend_class_entry *) pi->scope;
	GC_ADDREF(target);

	switch (pi->op) {
		case PROXY_PROP_GET:
			proxy_read_original_property(pi, target, result);
			break;
		case PROXY_PROP_SET:
			target->handlers->write_property(target, pi->name, &pi->value, NULL);
			pi->original_ran = true;
			ZVAL_NULL(result);
			break;
		case PROXY_PROP_ISSET:
			proxy_isset_original_property(pi, target, result);
			break;
		case PROXY_PROP_UNSET:
			target->handlers->unset_property(target, pi->name, NULL);
			pi->original_ran = true;
			ZVAL_NULL(result);
			break;
	}

	EG(fake_scope) = (zend_class_entry *) old_scope;
	OBJ_RELEASE(target);
	if (EG(exception)) {
		zval_ptr_dtor(result);
		ZVAL_UNDEF(result);
	}
}

/* Like method dispatch, preserve an active caller's SET value across each
 * proceed() while retaining the last completed value for an escaped object. */
static void proxy_run_property(proxy_prop_invocation *pi, bool intercept, zval *value, zval *result)
{
	zval saved_value;
	bool nested = pi->running;

	ZVAL_COPY_VALUE(&saved_value, &pi->value);
	if (pi->op == PROXY_PROP_SET && value) {
		ZVAL_COPY(&pi->value, value);
	} else {
		ZVAL_UNDEF(&pi->value);
	}
	pi->running = true;
	ZVAL_UNDEF(result);

	if (intercept && !Z_ISUNDEF(pi->interceptor)) {
		zval pi_zv;
		ZVAL_OBJ(&pi_zv, &pi->std);
		proxy_call_interceptor(&pi->interceptor, &pi_zv, result);
	} else {
		proxy_prop_original(pi, result);
	}

	pi->running = nested;
	proxy_restore_invocation_value(&pi->value, &saved_value, nested);
}

/* After a conditional read of an uninitialized property the interceptor may return
 * null for a non-nullable type (that is what the VM's `??` sees). If an
 * interceptor initialized the property after proceed() ran, the null is a
 * replacement value and must satisfy the declared type. */
static bool proxy_conditional_null_allowed(proxy_prop_invocation *pi)
{
	const zend_property_info *info = pi->info;
	if (!pi->conditional_unset) {
		return false;
	}
	if (!info || info->hooks || !pi->po->target || (info->flags & ZEND_ACC_VIRTUAL)) {
		return true;
	}
	zend_object *storage = proxy_storage_object(pi->po, false);
	return !storage || Z_TYPE_P(OBJ_PROP(storage, info->offset)) == IS_UNDEF;
}

static bool proxy_writable_slot_still_uninitialized(proxy_prop_invocation *pi)
{
	const zend_property_info *info = pi->info;
	if (info && !info->hooks && !(info->flags & ZEND_ACC_VIRTUAL)) {
		zend_object *storage = proxy_storage_object(pi->po, false);
		if (storage && Z_TYPE_P(OBJ_PROP(storage, info->offset)) != IS_UNDEF) {
			return false;
		}
	}
	return true;
}

static bool proxy_uninitialized_reference_forwarded(proxy_prop_invocation *pi, zval *result)
{
	if (!Z_ISREF_P(result) || Z_TYPE_P(Z_REFVAL_P(result)) != IS_NULL
			|| Z_ISUNDEF(pi->uninitialized_refs)) {
		return false;
	}
	zval *reference;
	ZEND_HASH_FOREACH_VAL(Z_ARRVAL(pi->uninitialized_refs), reference) {
		if (Z_REF_P(reference) == Z_REF_P(result)) {
			return true;
		}
	} ZEND_HASH_FOREACH_END();
	return false;
}

static zend_result proxy_dispatch_property_ex(proxy_object *po, zend_object *proxy_obj, int op, zend_string *name, int mode, zval *value, zend_class_entry *scope, zval *retval, zval *read_value, uint32_t *flags)
{
	/* Type coercion can invoke user code before the invocation is allocated. */
	GC_ADDREF(proxy_obj);
	zend_property_info *info;
	proxy_property_access access = proxy_property_check(po, op, name, mode, scope, &info);
	if (access == PROXY_PROPERTY_ERROR) {
		OBJ_RELEASE(proxy_obj);
		return FAILURE;
	}
	if (access == PROXY_PROPERTY_SILENT) {
		if (retval) {
			if (op == PROXY_PROP_GET) {
				/* unset() of an offset PHP refuses silently */
				ZVAL_NULL(retval);
				if (flags) {
					*flags = PROXY_GET_UNSET_SLOT;
				}
			} else {
				ZVAL_FALSE(retval);
			}
		}
		OBJ_RELEASE(proxy_obj);
		return SUCCESS;
	}

	zval coerced;
	if (op == PROXY_PROP_SET && info && ZEND_TYPE_IS_SET(info->type)) {
		/* Assignment consumes a value, even if its source is a typed reference.
		 * Coercion must not modify the caller's aliased property storage. */
		ZVAL_COPY_DEREF(&coerced, value);
		if (!proxy_verify_prop_write_type(info, &coerced)) {
			zval_ptr_dtor(&coerced);
			OBJ_RELEASE(proxy_obj);
			return FAILURE;
		}
		value = &coerced;
	} else {
		ZVAL_UNDEF(&coerced);
	}

	proxy_prop_invocation *pi = proxy_prop_invocation_from_obj(proxy_prop_invocation_create(proxy_ce_PropertyInvocation));
	/* Transfer the entry guard to the invocation. */
	pi->proxy = proxy_obj;
	pi->po = po;
	pi->name = zend_string_copy(name);
	pi->op = op;
	pi->mode = mode;
	pi->tracking_uninitialized_refs = true;
	if (op == PROXY_PROP_GET && mode == BP_VAR_W) {
		zend_execute_data *execute_data = EG(current_execute_data);
		if (execute_data && execute_data->func && ZEND_USER_CODE(execute_data->func->type)
				&& execute_data->opline
				&& (execute_data->opline->opcode == ZEND_FETCH_OBJ_W
					|| execute_data->opline->opcode == ZEND_FETCH_OBJ_FUNC_ARG)) {
			pi->fetch_flags = execute_data->opline->extended_value & ZEND_FETCH_OBJ_FLAGS;
		}
	}
	pi->scope = scope;
	pi->info = info;
	pi->empty_phase = read_value != NULL;
	if (read_value && !Z_ISUNDEF_P(read_value)) {
		ZVAL_COPY(&pi->read_value, read_value);
	}
	if (!Z_ISUNDEF(po->property_interceptor)) {
		ZVAL_COPY(&pi->interceptor, &po->property_interceptor);
	}

	zval result;
	zend_result rc = SUCCESS;
	proxy_run_property(pi, true, value, &result);
	bool uninitialized_forwarded = proxy_uninitialized_reference_forwarded(pi, &result);
	/* A saved invocation may outlive this handler and run proceed() later.
	 * Temporary identity records belong only to the completed dispatch. Clear
	 * them before validation, since releasing an abandoned reference may run
	 * a destructor that changes the target or throws. */
	pi->tracking_uninitialized_refs = false;
	zval uninitialized_refs;
	ZVAL_COPY_VALUE(&uninitialized_refs, &pi->uninitialized_refs);
	ZVAL_UNDEF(&pi->uninitialized_refs);
	zval_ptr_dtor(&uninitialized_refs);
	if (uninitialized_forwarded) {
		uninitialized_forwarded = Z_ISREF(result) && Z_TYPE_P(Z_REFVAL(result)) == IS_NULL
			&& proxy_writable_slot_still_uninitialized(pi);
	}
	if (EG(exception) && !Z_ISUNDEF(result)) {
		zval_ptr_dtor(&result);
		ZVAL_UNDEF(&result);
	}
	if (Z_ISUNDEF(result)) {
		if (EG(exception)) {
			rc = FAILURE;
		} else {
			ZVAL_NULL(&result);
		}
	}

	if (rc == SUCCESS) {
		switch (op) {
			case PROXY_PROP_GET: {
				/* Conditional reads may yield null for uninitialized storage even
				 * when the declared property type itself does not allow null.
				 * A writable (or unset) fetch must return its original
				 * uninitialized slot to the VM, which distinguishes array
				 * initialization from acquiring a reference to a non-nullable
				 * property and treats unset() of a missing offset as a no-op. A
				 * PHP callback exposes that original UNDEF result as null. */
				zval *v = &result;
				ZVAL_DEREF(v);
				bool unset_null = Z_TYPE_P(v) == IS_NULL
					&& ((mode == BP_VAR_IS && proxy_conditional_null_allowed(pi))
						|| ((mode == BP_VAR_W || mode == BP_VAR_UNSET) && uninitialized_forwarded));
				if (!unset_null && !proxy_verify_prop_type(info, &result)) {
					zval_ptr_dtor(&result);
					rc = FAILURE;
					break;
				}
				ZVAL_COPY_VALUE(retval, &result);
				break;
			}
			case PROXY_PROP_ISSET: {
				bool set = zend_is_true(&result);
				zval_ptr_dtor(&result);
				if (set && mode == ZEND_PROPERTY_NOT_EMPTY) {
					/* empty() reads the value after a positive isset(). PHP reads
					 * a property served by __get() directly with the getter (the
					 * BP_VAR_IS read would consult __isset() a second time). */
					int get_mode = proxy_type_of(po)->__get && proxy_prop_is_magic(po, info, name) ? BP_VAR_R : BP_VAR_IS;
					zval gv;
					if (proxy_dispatch_property_ex(po, proxy_obj, PROXY_PROP_GET, name, get_mode, NULL, scope, &gv, &pi->read_value, NULL) == FAILURE) {
						rc = FAILURE;
					} else {
						set = zend_is_true(&gv);
						zval_ptr_dtor(&gv);
					}
				}
				if (rc == SUCCESS) {
					ZVAL_BOOL(retval, set);
				}
				break;
			}
			default:
				zval_ptr_dtor(&result);
				break;
		}
	}

	if (flags) {
		*flags = (pi->target_notice ? PROXY_GET_TARGET_NOTICE : 0) | (uninitialized_forwarded ? PROXY_GET_UNSET_SLOT : 0)
			| (pi->rv_error ? PROXY_GET_RV_ERROR : 0) | (pi->original_ran ? PROXY_GET_ORIGINAL_RAN : 0);
	}
	zval_ptr_dtor(&coerced);
	OBJ_RELEASE(&pi->std);
	return rc;
}

zend_result proxy_dispatch_property(proxy_object *po, zend_object *proxy_obj, int op, zend_string *name, int mode, zval *value, zend_class_entry *scope, zval *retval)
{
	return proxy_dispatch_property_ex(po, proxy_obj, op, name, mode, value, scope, retval, NULL, NULL);
}

zend_result proxy_dispatch_property_get(proxy_object *po, zend_object *proxy_obj, zend_string *name, int mode, zend_class_entry *scope, zval *retval, uint32_t *flags)
{
	return proxy_dispatch_property_ex(po, proxy_obj, PROXY_PROP_GET, name, mode, NULL, scope, retval, NULL, flags);
}

/* ------------------------------------------------------------------------- */
/* Proxy\PropertyInvocation                                                  */
/* ------------------------------------------------------------------------- */

#define PROXY_PROP_INVOCATION_THIS() proxy_prop_invocation_from_obj(Z_OBJ_P(ZEND_THIS))

ZEND_METHOD(Proxy_PropertyInvocation, __construct)
{
	ZEND_PARSE_PARAMETERS_NONE();
}

ZEND_METHOD(Proxy_PropertyInvocation, property)
{
	ZEND_PARSE_PARAMETERS_NONE();
	proxy_prop_invocation *pi = PROXY_PROP_INVOCATION_THIS();
	RETURN_STR_COPY(pi->name);
}

ZEND_METHOD(Proxy_PropertyInvocation, operation)
{
	ZEND_PARSE_PARAMETERS_NONE();
	proxy_prop_invocation *pi = PROXY_PROP_INVOCATION_THIS();
	zend_object *op = zend_enum_get_case_cstr(proxy_ce_PropertyOperation, proxy_prop_op_names[pi->op]);
	RETURN_OBJ_COPY(op);
}

ZEND_METHOD(Proxy_PropertyInvocation, value)
{
	ZEND_PARSE_PARAMETERS_NONE();
	proxy_prop_invocation *pi = PROXY_PROP_INVOCATION_THIS();
	if (Z_ISUNDEF(pi->value)) {
		RETURN_NULL();
	}
	RETURN_COPY_DEREF(&pi->value);
}

ZEND_METHOD(Proxy_PropertyInvocation, proxy)
{
	ZEND_PARSE_PARAMETERS_NONE();
	proxy_prop_invocation *pi = PROXY_PROP_INVOCATION_THIS();
	RETURN_OBJ_COPY(pi->proxy);
}

ZEND_METHOD(Proxy_PropertyInvocation, target)
{
	ZEND_PARSE_PARAMETERS_NONE();
	proxy_prop_invocation *pi = PROXY_PROP_INVOCATION_THIS();
	if (pi->po->target) {
		RETURN_OBJ_COPY(pi->po->target);
	}
	RETURN_NULL();
}

ZEND_METHOD(Proxy_PropertyInvocation, class)
{
	ZEND_PARSE_PARAMETERS_NONE();
	proxy_prop_invocation *pi = PROXY_PROP_INVOCATION_THIS();
	RETURN_STR_COPY(proxy_type_of(pi->po)->name);
}

ZEND_METHOD(Proxy_PropertyInvocation, hasOriginal)
{
	ZEND_PARSE_PARAMETERS_NONE();
	proxy_prop_invocation *pi = PROXY_PROP_INVOCATION_THIS();
	RETURN_BOOL(pi->po->target != NULL);
}

ZEND_METHOD(Proxy_PropertyInvocation, proceed)
{
	zval *args;
	uint32_t argc;
	HashTable *named = NULL;

	ZEND_PARSE_PARAMETERS_START(0, -1)
		Z_PARAM_VARIADIC_WITH_NAMED(args, argc, named)
	ZEND_PARSE_PARAMETERS_END();

	proxy_prop_invocation *pi = PROXY_PROP_INVOCATION_THIS();
	uint32_t expected = pi->op == PROXY_PROP_SET ? 1 : 0;

	if (named && zend_hash_num_elements(named)) {
		zend_argument_count_error("Proxy\\PropertyInvocation::proceed() does not accept named arguments");
		RETURN_THROWS();
	}
	if (argc != expected) {
		zend_argument_count_error("Proxy\\PropertyInvocation::proceed() expects exactly %u argument%s for %s operations, %u given",
			expected, expected == 1 ? "" : "s", proxy_prop_op_names[pi->op], argc);
		RETURN_THROWS();
	}

	zval result;
	proxy_run_property(pi, false, argc ? &args[0] : NULL, &result);
	if (Z_ISUNDEF(result)) {
		if (EG(exception)) {
			RETURN_THROWS();
		}
		ZVAL_NULL(&result);
	}
	/* Keep native hook references intact. Ordinary value results use a local
	 * reference container, just like Invocation::proceed(). Each callback
	 * chooses whether to return that reference or its value using PHP syntax. */
	if (!Z_ISREF(result)) {
		ZVAL_NEW_REF(&result, &result);
	}
	ZVAL_COPY_VALUE(return_value, &result);
}
