PHP_ARG_ENABLE([proxy],
  [whether to enable proxy support],
  [AS_HELP_STRING([--enable-proxy],
    [Enable engine-level proxy objects])],
  [no])

if test "$PHP_PROXY" != "no"; then
  AC_DEFINE(HAVE_PROXY, 1, [ Have proxy support ])

  PHP_NEW_EXTENSION(proxy,
    proxy.c proxy_class.c proxy_class_name.c proxy_object.c proxy_dispatch.c proxy_iterator.c,
    $ext_shared,, -DZEND_ENABLE_STATIC_TSRMLS_CACHE=1)

  PHP_INSTALL_HEADERS([ext/proxy], [php_proxy.h])
fi
