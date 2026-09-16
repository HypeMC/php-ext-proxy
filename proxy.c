/*
 * ext/proxy - module entry and the Proxy facade
 */

#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php_proxy.h"
#include "ext/standard/info.h"
#include "zend_closures.h"
#include "zend_enum.h"
#include "zend_extensions.h"
#include "proxy_arginfo.h"

ZEND_DECLARE_MODULE_GLOBALS(proxy)

zend_class_entry *proxy_ce_Proxy;
zend_class_entry *proxy_ce_Invocation;
zend_class_entry *proxy_ce_PropertyInvocation;
zend_class_entry *proxy_ce_PropertyOperation;
zend_class_entry *proxy_ce_ProxyException;
zend_class_entry *proxy_ce_UnconfiguredMethod;
zend_class_entry *proxy_ce_UnconfiguredProperty;
zend_class_entry *proxy_ce_NoOriginalImplementation;
zend_class_entry *proxy_ce_InvalidProxyTarget;
zend_class_entry *proxy_ce_InvalidInterceptor;
zend_class_entry *proxy_ce_UnsupportedProxyType;
zend_class_entry *proxy_ce_UnsupportedOperation;

int proxy_reserved_slot = -1;

ZEND_COLD void proxy_throw(zend_class_entry *ce, const char *format, ...)
{
	va_list args;
	char *message;

	va_start(args, format);
	zend_vspprintf(&message, 0, format, args);
	va_end(args);
	zend_throw_exception(ce, message, 0);
	efree(message);
}

/* ------------------------------------------------------------------------- */
/* Interceptor configuration                                                 */
/* ------------------------------------------------------------------------- */

/* Resolve visibility at registration time, just like Closure::fromCallable().
 * Keeping the raw callable would resolve a private/protected method again in
 * the scope of whoever later invokes the proxy. Let Zend also handle bound
 * objects, existing closures, and magic-call trampolines and their lifetimes. */
static zend_result proxy_normalize_interceptor(zval *callable, zval *closure)
{
	if (Z_TYPE_P(callable) == IS_OBJECT && Z_OBJCE_P(callable) == zend_ce_closure) {
		ZVAL_COPY(closure, callable);
		return SUCCESS;
	}

	zend_function *from_callable = zend_hash_str_find_ptr(&zend_ce_closure->function_table,
		"fromcallable", sizeof("fromcallable") - 1);
	ZVAL_UNDEF(closure);
	zend_call_known_function(from_callable, NULL, zend_ce_closure, closure, 1, callable, NULL);
	return EG(exception) ? FAILURE : SUCCESS;
}

static void proxy_create_and_configure(zval *return_value,
	zend_class_entry *type_ce, zend_object *target,
	zend_fcall_info *method_fci, zend_fcall_info *property_fci)
{
	zval method_interceptor, property_interceptor;
	ZVAL_UNDEF(&method_interceptor);
	ZVAL_UNDEF(&property_interceptor);

	proxy_class *cls = proxy_class_get(type_ce);
	if (!cls) {
		goto fail;
	}
	if (ZEND_FCI_INITIALIZED(*method_fci)
			&& proxy_normalize_interceptor(&method_fci->function_name, &method_interceptor) == FAILURE) {
		goto fail;
	}
	if (ZEND_FCI_INITIALIZED(*property_fci)
			&& proxy_normalize_interceptor(&property_fci->function_name, &property_interceptor) == FAILURE) {
		goto fail;
	}

	PROXY_G(creating) = true;
	zend_object *obj = proxy_create_object(cls->ce);
	PROXY_G(creating) = false;
	proxy_object *po = proxy_object_from_obj(obj);
	proxy_object_configure(po, target,
		Z_ISUNDEF(method_interceptor) ? NULL : &method_interceptor,
		Z_ISUNDEF(property_interceptor) ? NULL : &property_interceptor);
	zval_ptr_dtor(&method_interceptor);
	zval_ptr_dtor(&property_interceptor);
	RETURN_OBJ(obj);

fail:
	zval_ptr_dtor(&method_interceptor);
	zval_ptr_dtor(&property_interceptor);
	RETURN_THROWS();
}

/* ------------------------------------------------------------------------- */
/* Proxy                                                                     */
/* ------------------------------------------------------------------------- */

ZEND_METHOD(Proxy_Proxy, __construct)
{
	ZEND_PARSE_PARAMETERS_NONE();
}

ZEND_METHOD(Proxy_Proxy, mock)
{
	zend_string *class_name;
	zend_fcall_info method_fci = empty_fcall_info, property_fci = empty_fcall_info;
	zend_fcall_info_cache method_fcc = empty_fcall_info_cache, property_fcc = empty_fcall_info_cache;

	ZEND_PARSE_PARAMETERS_START(1, 3)
		Z_PARAM_STR(class_name)
		Z_PARAM_OPTIONAL
		Z_PARAM_FUNC_OR_NULL(method_fci, method_fcc)
		Z_PARAM_FUNC_OR_NULL(property_fci, property_fcc)
	ZEND_PARSE_PARAMETERS_END();

	/* Parsing checks callability; normalization below owns the retained closures. */
	zend_release_fcall_info_cache(&method_fcc);
	zend_release_fcall_info_cache(&property_fcc);

	zend_class_entry *ce = zend_lookup_class(class_name);
	if (!ce) {
		if (!EG(exception)) {
			proxy_throw(proxy_ce_InvalidProxyTarget, "Class \"%s\" does not exist", ZSTR_VAL(class_name));
		}
		RETURN_THROWS();
	}
	if (proxy_class_validate_type(ce, false) == FAILURE) {
		RETURN_THROWS();
	}

	proxy_create_and_configure(return_value, ce, NULL, &method_fci, &property_fci);
}

ZEND_METHOD(Proxy_Proxy, wrap)
{
	zend_object *target;
	zend_fcall_info method_fci = empty_fcall_info, property_fci = empty_fcall_info;
	zend_fcall_info_cache method_fcc = empty_fcall_info_cache, property_fcc = empty_fcall_info_cache;

	ZEND_PARSE_PARAMETERS_START(1, 3)
		Z_PARAM_OBJ(target)
		Z_PARAM_OPTIONAL
		Z_PARAM_FUNC_OR_NULL(method_fci, method_fcc)
		Z_PARAM_FUNC_OR_NULL(property_fci, property_fcc)
	ZEND_PARSE_PARAMETERS_END();

	zend_release_fcall_info_cache(&method_fcc);
	zend_release_fcall_info_cache(&property_fcc);

	if (target->ce->ce_flags & ZEND_ACC_ENUM) {
		proxy_throw(proxy_ce_UnsupportedProxyType, "Enum case %s::%s cannot be proxied",
			ZSTR_VAL(target->ce->name), Z_STRVAL_P(zend_enum_fetch_case_name(target)));
		RETURN_THROWS();
	}
	if (proxy_is_proxy(target)) {
		proxy_throw(proxy_ce_InvalidProxyTarget, "Object of class %s is already a proxy and cannot be wrapped",
			ZSTR_VAL(target->ce->name));
		RETURN_THROWS();
	}
	if (proxy_class_validate_type(target->ce, true) == FAILURE) {
		RETURN_THROWS();
	}

	proxy_create_and_configure(return_value, target->ce, target, &method_fci, &property_fci);
}

ZEND_METHOD(Proxy_Proxy, isProxy)
{
	zend_object *obj;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_OBJ(obj)
	ZEND_PARSE_PARAMETERS_END();

	RETURN_BOOL(proxy_is_proxy(obj) && proxy_object_from_obj(obj) != NULL);
}

static proxy_object *proxy_require_proxy(zend_object *obj)
{
	if (!proxy_is_proxy(obj)) {
		proxy_throw(proxy_ce_InvalidProxyTarget, "Object of class %s is not a proxy", ZSTR_VAL(obj->ce->name));
		return NULL;
	}
	proxy_object *po = proxy_object_from_obj(obj);
	if (!po || !po->initialized) {
		proxy_throw(proxy_ce_UnsupportedOperation,
			"Proxy object of class %s was not created through Proxy\\Proxy::mock() or Proxy\\Proxy::wrap()",
			ZSTR_VAL(obj->ce->name));
		return NULL;
	}
	return po;
}

ZEND_METHOD(Proxy_Proxy, target)
{
	zend_object *obj;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_OBJ(obj)
	ZEND_PARSE_PARAMETERS_END();

	proxy_object *po = proxy_require_proxy(obj);
	if (!po) {
		RETURN_THROWS();
	}
	if (po->target) {
		RETURN_OBJ_COPY(po->target);
	}
	RETURN_NULL();
}

/* ------------------------------------------------------------------------- */
/* Module                                                                    */
/* ------------------------------------------------------------------------- */

static PHP_GINIT_FUNCTION(proxy)
{
#if defined(COMPILE_DL_PROXY) && defined(ZTS)
	ZEND_TSRMLS_CACHE_UPDATE();
#endif
	memset(proxy_globals, 0, sizeof(*proxy_globals));
}

static PHP_MINIT_FUNCTION(proxy)
{
	if (type == MODULE_TEMPORARY) {
		/* Existing compiled code cannot be retrofitted by an AST hook. Refuse
		 * dl() before registering any classes instead of mixing name rules. */
		return proxy_class_name_minit(type);
	}
	proxy_reserved_slot = zend_get_resource_handle("proxy");
	if (proxy_reserved_slot < 0) {
		zend_error(E_CORE_WARNING, "proxy: unable to reserve a zend_function resource slot");
		return FAILURE;
	}

	proxy_ce_Proxy = register_class_Proxy_Proxy();
	proxy_ce_Invocation = register_class_Proxy_Invocation();
	proxy_ce_PropertyInvocation = register_class_Proxy_PropertyInvocation();
	proxy_ce_PropertyOperation = register_class_Proxy_PropertyOperation();
	proxy_ce_ProxyException = register_class_Proxy_Exception_ProxyException(zend_ce_exception);
	proxy_ce_UnconfiguredMethod = register_class_Proxy_Exception_UnconfiguredMethod(proxy_ce_ProxyException);
	proxy_ce_UnconfiguredProperty = register_class_Proxy_Exception_UnconfiguredProperty(proxy_ce_ProxyException);
	proxy_ce_NoOriginalImplementation = register_class_Proxy_Exception_NoOriginalImplementation(proxy_ce_ProxyException);
	proxy_ce_InvalidProxyTarget = register_class_Proxy_Exception_InvalidProxyTarget(proxy_ce_ProxyException);
	proxy_ce_InvalidInterceptor = register_class_Proxy_Exception_InvalidInterceptor(proxy_ce_ProxyException);
	proxy_ce_UnsupportedProxyType = register_class_Proxy_Exception_UnsupportedProxyType(proxy_ce_ProxyException);
	proxy_ce_UnsupportedOperation = register_class_Proxy_Exception_UnsupportedOperation(proxy_ce_ProxyException);

	proxy_dispatch_minit();
	return proxy_class_name_minit(type);
}

static PHP_MSHUTDOWN_FUNCTION(proxy)
{
	proxy_class_name_mshutdown();
	return SUCCESS;
}

static PHP_RINIT_FUNCTION(proxy)
{
#if defined(COMPILE_DL_PROXY) && defined(ZTS)
	ZEND_TSRMLS_CACHE_UPDATE();
#endif
	zend_hash_init(&PROXY_G(classes), 8, NULL, NULL, 0);
	zend_hash_init(&PROXY_G(objects), 8, NULL, NULL, 0);
	PROXY_G(class_counter) = 0;
	PROXY_G(tables_initialized) = true;
	PROXY_G(creating) = false;
	return SUCCESS;
}

/* User code still runs after RSHUTDOWN: resource destructors such as a
 * stream wrapper's stream_close() may call proxies, resolve methods (cache
 * entries) or even create proxies of new types. All request memory of the
 * extension is therefore released only after the executor shut down. Class
 * descriptions and trampolines live in CG(arena), which is destroyed later. */
static ZEND_MODULE_POST_ZEND_DEACTIVATE_D(proxy)
{
	if (PROXY_G(tables_initialized)) {
		proxy_class *cls;
		ZEND_HASH_FOREACH_PTR(&PROXY_G(classes), cls) {
			zend_hash_destroy(&cls->method_cache);
		} ZEND_HASH_FOREACH_END();
		zend_hash_destroy(&PROXY_G(objects));
		zend_hash_destroy(&PROXY_G(classes));
		PROXY_G(tables_initialized) = false;
	}
	return SUCCESS;
}

static PHP_MINFO_FUNCTION(proxy)
{
	php_info_print_table_start();
	php_info_print_table_row(2, "proxy support", "enabled");
	php_info_print_table_row(2, "Version", PHP_PROXY_VERSION);
	php_info_print_table_end();
}

zend_module_entry proxy_module_entry = {
	STANDARD_MODULE_HEADER,
	"proxy",
	NULL,
	PHP_MINIT(proxy),
	PHP_MSHUTDOWN(proxy),
	PHP_RINIT(proxy),
	NULL,
	PHP_MINFO(proxy),
	PHP_PROXY_VERSION,
	PHP_MODULE_GLOBALS(proxy),
	PHP_GINIT(proxy),
	NULL,
	ZEND_MODULE_POST_ZEND_DEACTIVATE_N(proxy),
	STANDARD_MODULE_PROPERTIES_EX
};

#ifdef COMPILE_DL_PROXY
# ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
# endif
ZEND_GET_MODULE(proxy)
#endif
