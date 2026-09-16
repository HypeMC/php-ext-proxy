/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: a4c745c616218df09031c41062da2cd4606a2860 */

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_Proxy_Proxy___construct, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Proxy_Proxy_mock, 0, 1, IS_OBJECT, 0)
	ZEND_ARG_TYPE_INFO(0, class, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, methodInterceptor, IS_CALLABLE, 1, "null")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, propertyInterceptor, IS_CALLABLE, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Proxy_Proxy_wrap, 0, 1, IS_OBJECT, 0)
	ZEND_ARG_TYPE_INFO(0, target, IS_OBJECT, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, methodInterceptor, IS_CALLABLE, 1, "null")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, propertyInterceptor, IS_CALLABLE, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Proxy_Proxy_isProxy, 0, 1, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO(0, object, IS_OBJECT, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Proxy_Proxy_target, 0, 1, IS_OBJECT, 1)
	ZEND_ARG_TYPE_INFO(0, proxy, IS_OBJECT, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Proxy_Invocation___construct arginfo_class_Proxy_Proxy___construct

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Proxy_Invocation_method, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Proxy_Invocation_args, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Proxy_Invocation_arg, 0, 1, IS_MIXED, 0)
	ZEND_ARG_TYPE_INFO(0, index, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Proxy_Invocation_proxy, 0, 0, IS_OBJECT, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Proxy_Invocation_target, 0, 0, IS_OBJECT, 1)
ZEND_END_ARG_INFO()

#define arginfo_class_Proxy_Invocation_class arginfo_class_Proxy_Invocation_method

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Proxy_Invocation_hasOriginal, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Proxy_Invocation_proceed, 1, 0, IS_MIXED, 0)
	ZEND_ARG_VARIADIC_TYPE_INFO(ZEND_SEND_PREFER_REF, args, IS_MIXED, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Proxy_PropertyInvocation___construct arginfo_class_Proxy_Proxy___construct

#define arginfo_class_Proxy_PropertyInvocation_property arginfo_class_Proxy_Invocation_method

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Proxy_PropertyInvocation_operation, 0, 0, Proxy\\PropertyOperation, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Proxy_PropertyInvocation_value, 0, 0, IS_MIXED, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Proxy_PropertyInvocation_proxy arginfo_class_Proxy_Invocation_proxy

#define arginfo_class_Proxy_PropertyInvocation_target arginfo_class_Proxy_Invocation_target

#define arginfo_class_Proxy_PropertyInvocation_class arginfo_class_Proxy_Invocation_method

#define arginfo_class_Proxy_PropertyInvocation_hasOriginal arginfo_class_Proxy_Invocation_hasOriginal

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Proxy_PropertyInvocation_proceed, 1, 0, IS_MIXED, 0)
	ZEND_ARG_VARIADIC_TYPE_INFO(0, args, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_METHOD(Proxy_Proxy, __construct);
ZEND_METHOD(Proxy_Proxy, mock);
ZEND_METHOD(Proxy_Proxy, wrap);
ZEND_METHOD(Proxy_Proxy, isProxy);
ZEND_METHOD(Proxy_Proxy, target);
ZEND_METHOD(Proxy_Invocation, __construct);
ZEND_METHOD(Proxy_Invocation, method);
ZEND_METHOD(Proxy_Invocation, args);
ZEND_METHOD(Proxy_Invocation, arg);
ZEND_METHOD(Proxy_Invocation, proxy);
ZEND_METHOD(Proxy_Invocation, target);
ZEND_METHOD(Proxy_Invocation, class);
ZEND_METHOD(Proxy_Invocation, hasOriginal);
ZEND_METHOD(Proxy_Invocation, proceed);
ZEND_METHOD(Proxy_PropertyInvocation, __construct);
ZEND_METHOD(Proxy_PropertyInvocation, property);
ZEND_METHOD(Proxy_PropertyInvocation, operation);
ZEND_METHOD(Proxy_PropertyInvocation, value);
ZEND_METHOD(Proxy_PropertyInvocation, proxy);
ZEND_METHOD(Proxy_PropertyInvocation, target);
ZEND_METHOD(Proxy_PropertyInvocation, class);
ZEND_METHOD(Proxy_PropertyInvocation, hasOriginal);
ZEND_METHOD(Proxy_PropertyInvocation, proceed);

static const zend_function_entry class_Proxy_Proxy_methods[] = {
	ZEND_ME(Proxy_Proxy, __construct, arginfo_class_Proxy_Proxy___construct, ZEND_ACC_PRIVATE)
	ZEND_ME(Proxy_Proxy, mock, arginfo_class_Proxy_Proxy_mock, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_ME(Proxy_Proxy, wrap, arginfo_class_Proxy_Proxy_wrap, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_ME(Proxy_Proxy, isProxy, arginfo_class_Proxy_Proxy_isProxy, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_ME(Proxy_Proxy, target, arginfo_class_Proxy_Proxy_target, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_FE_END
};

static const zend_function_entry class_Proxy_Invocation_methods[] = {
	ZEND_ME(Proxy_Invocation, __construct, arginfo_class_Proxy_Invocation___construct, ZEND_ACC_PRIVATE)
	ZEND_ME(Proxy_Invocation, method, arginfo_class_Proxy_Invocation_method, ZEND_ACC_PUBLIC)
	ZEND_ME(Proxy_Invocation, args, arginfo_class_Proxy_Invocation_args, ZEND_ACC_PUBLIC)
	ZEND_ME(Proxy_Invocation, arg, arginfo_class_Proxy_Invocation_arg, ZEND_ACC_PUBLIC)
	ZEND_ME(Proxy_Invocation, proxy, arginfo_class_Proxy_Invocation_proxy, ZEND_ACC_PUBLIC)
	ZEND_ME(Proxy_Invocation, target, arginfo_class_Proxy_Invocation_target, ZEND_ACC_PUBLIC)
	ZEND_ME(Proxy_Invocation, class, arginfo_class_Proxy_Invocation_class, ZEND_ACC_PUBLIC)
	ZEND_ME(Proxy_Invocation, hasOriginal, arginfo_class_Proxy_Invocation_hasOriginal, ZEND_ACC_PUBLIC)
	ZEND_ME(Proxy_Invocation, proceed, arginfo_class_Proxy_Invocation_proceed, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static const zend_function_entry class_Proxy_PropertyInvocation_methods[] = {
	ZEND_ME(Proxy_PropertyInvocation, __construct, arginfo_class_Proxy_PropertyInvocation___construct, ZEND_ACC_PRIVATE)
	ZEND_ME(Proxy_PropertyInvocation, property, arginfo_class_Proxy_PropertyInvocation_property, ZEND_ACC_PUBLIC)
	ZEND_ME(Proxy_PropertyInvocation, operation, arginfo_class_Proxy_PropertyInvocation_operation, ZEND_ACC_PUBLIC)
	ZEND_ME(Proxy_PropertyInvocation, value, arginfo_class_Proxy_PropertyInvocation_value, ZEND_ACC_PUBLIC)
	ZEND_ME(Proxy_PropertyInvocation, proxy, arginfo_class_Proxy_PropertyInvocation_proxy, ZEND_ACC_PUBLIC)
	ZEND_ME(Proxy_PropertyInvocation, target, arginfo_class_Proxy_PropertyInvocation_target, ZEND_ACC_PUBLIC)
	ZEND_ME(Proxy_PropertyInvocation, class, arginfo_class_Proxy_PropertyInvocation_class, ZEND_ACC_PUBLIC)
	ZEND_ME(Proxy_PropertyInvocation, hasOriginal, arginfo_class_Proxy_PropertyInvocation_hasOriginal, ZEND_ACC_PUBLIC)
	ZEND_ME(Proxy_PropertyInvocation, proceed, arginfo_class_Proxy_PropertyInvocation_proceed, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static zend_class_entry *register_class_Proxy_Proxy(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Proxy", "Proxy", class_Proxy_Proxy_methods);
	class_entry = zend_register_internal_class_with_flags(&ce, NULL, ZEND_ACC_FINAL|ZEND_ACC_NO_DYNAMIC_PROPERTIES|ZEND_ACC_NOT_SERIALIZABLE);

	return class_entry;
}

static zend_class_entry *register_class_Proxy_Invocation(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Proxy", "Invocation", class_Proxy_Invocation_methods);
	class_entry = zend_register_internal_class_with_flags(&ce, NULL, ZEND_ACC_FINAL|ZEND_ACC_NO_DYNAMIC_PROPERTIES|ZEND_ACC_NOT_SERIALIZABLE);

	return class_entry;
}

static zend_class_entry *register_class_Proxy_PropertyInvocation(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Proxy", "PropertyInvocation", class_Proxy_PropertyInvocation_methods);
	class_entry = zend_register_internal_class_with_flags(&ce, NULL, ZEND_ACC_FINAL|ZEND_ACC_NO_DYNAMIC_PROPERTIES|ZEND_ACC_NOT_SERIALIZABLE);

	return class_entry;
}

static zend_class_entry *register_class_Proxy_PropertyOperation(void)
{
	zend_class_entry *class_entry = zend_register_internal_enum("Proxy\\PropertyOperation", IS_UNDEF, NULL);

	zend_enum_add_case_cstr(class_entry, "GET", NULL);

	zend_enum_add_case_cstr(class_entry, "SET", NULL);

	zend_enum_add_case_cstr(class_entry, "ISSET", NULL);

	zend_enum_add_case_cstr(class_entry, "UNSET", NULL);

	return class_entry;
}

static zend_class_entry *register_class_Proxy_Exception_ProxyException(zend_class_entry *class_entry_Exception)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Proxy\\Exception", "ProxyException", NULL);
	class_entry = zend_register_internal_class_with_flags(&ce, class_entry_Exception, 0);

	return class_entry;
}

static zend_class_entry *register_class_Proxy_Exception_UnconfiguredMethod(zend_class_entry *class_entry_Proxy_Exception_ProxyException)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Proxy\\Exception", "UnconfiguredMethod", NULL);
	class_entry = zend_register_internal_class_with_flags(&ce, class_entry_Proxy_Exception_ProxyException, 0);

	return class_entry;
}

static zend_class_entry *register_class_Proxy_Exception_UnconfiguredProperty(zend_class_entry *class_entry_Proxy_Exception_ProxyException)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Proxy\\Exception", "UnconfiguredProperty", NULL);
	class_entry = zend_register_internal_class_with_flags(&ce, class_entry_Proxy_Exception_ProxyException, 0);

	return class_entry;
}

static zend_class_entry *register_class_Proxy_Exception_NoOriginalImplementation(zend_class_entry *class_entry_Proxy_Exception_ProxyException)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Proxy\\Exception", "NoOriginalImplementation", NULL);
	class_entry = zend_register_internal_class_with_flags(&ce, class_entry_Proxy_Exception_ProxyException, 0);

	return class_entry;
}

static zend_class_entry *register_class_Proxy_Exception_InvalidProxyTarget(zend_class_entry *class_entry_Proxy_Exception_ProxyException)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Proxy\\Exception", "InvalidProxyTarget", NULL);
	class_entry = zend_register_internal_class_with_flags(&ce, class_entry_Proxy_Exception_ProxyException, 0);

	return class_entry;
}

static zend_class_entry *register_class_Proxy_Exception_InvalidInterceptor(zend_class_entry *class_entry_Proxy_Exception_ProxyException)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Proxy\\Exception", "InvalidInterceptor", NULL);
	class_entry = zend_register_internal_class_with_flags(&ce, class_entry_Proxy_Exception_ProxyException, 0);

	return class_entry;
}

static zend_class_entry *register_class_Proxy_Exception_UnsupportedProxyType(zend_class_entry *class_entry_Proxy_Exception_ProxyException)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Proxy\\Exception", "UnsupportedProxyType", NULL);
	class_entry = zend_register_internal_class_with_flags(&ce, class_entry_Proxy_Exception_ProxyException, 0);

	return class_entry;
}

static zend_class_entry *register_class_Proxy_Exception_UnsupportedOperation(zend_class_entry *class_entry_Proxy_Exception_ProxyException)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Proxy\\Exception", "UnsupportedOperation", NULL);
	class_entry = zend_register_internal_class_with_flags(&ce, class_entry_Proxy_Exception_ProxyException, 0);

	return class_entry;
}
