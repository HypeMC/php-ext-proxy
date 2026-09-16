/*
 * ext/proxy - engine-level proxy objects for PHP
 *
 * Internal header shared by the extension's translation units.
 */

#ifndef PHP_PROXY_H
#define PHP_PROXY_H

#include "php.h"
#include "zend_interfaces.h"
#include "zend_exceptions.h"

extern zend_module_entry proxy_module_entry;
#define phpext_proxy_ptr &proxy_module_entry

#define PHP_PROXY_VERSION "0.1.0"

#if PHP_VERSION_ID < 80400
# error "ext/proxy requires PHP 8.4 or newer (property hooks and asymmetric visibility)"
#endif

#if defined(ZTS) && defined(COMPILE_DL_PROXY)
ZEND_TSRMLS_CACHE_EXTERN()
#endif

typedef struct _proxy_class proxy_class;
typedef struct _proxy_object proxy_object;
typedef struct _proxy_method proxy_method;
typedef struct _proxy_raw_walk proxy_raw_walk;

/* Per generated class object handler table. The back pointer lets every
 * handler recover the class description in O(1) from zend_object.handlers. */
typedef struct _proxy_handlers {
	zend_object_handlers h; /* MUST be first */
	proxy_class *cls;
} proxy_handlers;

/* Description of one generated proxy class. Allocated from CG(arena) and
 * therefore valid until the end of the request (after all objects and
 * classes have been destroyed). */
struct _proxy_class {
	zend_class_entry ce_storage;  /* generated class entry; see proxy_class_from_ce() */
	zend_class_entry *ce;         /* &ce_storage */
	zend_class_entry *type_ce;    /* proxied class or interface */
	proxy_handlers handlers;     /* installed on each configured proxy */

	/* Internal ancestors supply the allocator and their native object handlers. */
	const zend_object_handlers *parent_handlers;
	zend_object *(*parent_create_object)(zend_class_entry *ce);
	bool embedded;               /* proxy_object precedes zend_object in memory */
	bool has_call;               /* proxied type has __call() */
	HashTable method_cache;      /* original zend_function pointer => proxy_method* */
};

/* Trampoline stored in the generated class' function table for every
 * instance method of the proxied type. Allocated from CG(arena); the
 * ZEND_ACC_ARENA_ALLOCATED flag tells zend_function_dtor() not to free it. */
struct _proxy_method {
	zend_internal_function fn;    /* MUST be first */
	zend_function *original;      /* method of the proxied type */
	proxy_class *cls;
	bool has_body;                /* original is not abstract */
};

/* Per instance state. For proxies of user classes this struct is embedded
 * in front of the zend_object; for proxies of internal classes the object is
 * allocated by the internal class and the state lives in PROXY_G(objects). */
struct _proxy_object {
	proxy_class *cls;            /* borrowed request-lifetime metadata */
	zend_object *target;         /* owned reference, or NULL for mock() */
	zval method_interceptor;     /* owned callable or UNDEF */
	zval property_interceptor;   /* owned callable or UNDEF */
	bool initialized;            /* configured through mock()/wrap() */
	proxy_raw_walk *raw_walks;   /* active native property-table walks */
	zend_object *obj;            /* object this state belongs to; not an owned reference */
	zend_object std;             /* embedded layout only: MUST be last */
};

/* An invocation keeps its proxy and captured values alive after the callback
 * returns. Its po/pm pointers borrow storage protected by that proxy reference.
 * running distinguishes nested proceed() from a later use of a saved invocation. */
typedef struct _proxy_invocation {
	zend_object *proxy;           /* owned reference */
	proxy_object *po;
	proxy_method *pm;             /* NULL for names dispatched to __call() */
	zend_string *name;            /* owned method name (declared spelling) */
	zval args;                    /* current call arguments (array) or UNDEF */
	zval interceptor;             /* callable or UNDEF */
	bool running;                 /* restore an active caller's argument snapshot */
	zend_object std;
} proxy_invocation;

enum {
	PROXY_PROP_GET = 0,
	PROXY_PROP_SET = 1,
	PROXY_PROP_ISSET = 2,
	PROXY_PROP_UNSET = 3,
};

typedef struct _proxy_prop_invocation {
	zend_object *proxy;           /* owned reference */
	proxy_object *po;             /* borrowed state, kept alive by proxy */
	zend_string *name;            /* owned property name */

	/* Original access context: callbacks must not change visibility or fetch mode. */
	int op;
	int mode;                    /* BP_VAR_* for GET, has_set_exists for ISSET */
	uint32_t fetch_flags;         /* writable fetch context before interception */
	zend_class_entry *scope;      /* scope of the original access, used when delegating */
	zend_property_info *info;     /* visible declared property, if any */

	/* Captured values and aliases used to recognize native uninitialized storage. */
	zval value;                   /* current SET value or UNDEF */
	zval read_value;              /* getter result shared by the ISSET/GET phases of empty() */
	zval uninitialized_refs;      /* original uninitialized writable references */
	bool conditional_unset;       /* target supplied PHP's uninitialized conditional-read result */
	bool writable_unset;          /* original writable fetch supplied an uninitialized slot */
	bool tracking_uninitialized_refs; /* track aliases only during the active property handler */

	/* Facts passed back to the object handler after the callback completes. */
	bool original_ran;            /* the target operation ran during this dispatch */
	bool target_notice;           /* target __get() already emitted the writable-fetch notice */
	bool empty_phase;             /* GET phase of empty(): a positive __isset() was already consulted */
	bool rv_error;                /* target read_property() returned its own rv while throwing */

	zval interceptor;             /* callable or UNDEF */
	bool running;                 /* restore an active caller's value snapshot */
	zend_object std;
} proxy_prop_invocation;

ZEND_BEGIN_MODULE_GLOBALS(proxy)
	HashTable classes;            /* (zend_ulong) type_ce => proxy_class* */
	HashTable objects;            /* object handle => proxy_object* (non-embedded) */
	zend_ulong class_counter;
	bool tables_initialized;
	bool creating;                /* proxy_create_object() is called by our own factory */
ZEND_END_MODULE_GLOBALS(proxy)

ZEND_EXTERN_MODULE_GLOBALS(proxy)
#define PROXY_G(v) ZEND_MODULE_GLOBALS_ACCESSOR(proxy, v)

/* Class entries registered in MINIT */
extern zend_class_entry *proxy_ce_Proxy;
extern zend_class_entry *proxy_ce_Invocation;
extern zend_class_entry *proxy_ce_PropertyInvocation;
extern zend_class_entry *proxy_ce_PropertyOperation;
extern zend_class_entry *proxy_ce_ProxyException;
extern zend_class_entry *proxy_ce_UnconfiguredMethod;
extern zend_class_entry *proxy_ce_UnconfiguredProperty;
extern zend_class_entry *proxy_ce_NoOriginalImplementation;
extern zend_class_entry *proxy_ce_InvalidProxyTarget;
extern zend_class_entry *proxy_ce_InvalidInterceptor;
extern zend_class_entry *proxy_ce_UnsupportedProxyType;
extern zend_class_entry *proxy_ce_UnsupportedOperation;

/* Index into zend_internal_function.reserved[] holding the proxy_method /
 * proxy_class pointer of a trampoline. */
extern int proxy_reserved_slot;

/* Compile-time class-name mapping; no executor or opcode handlers are replaced. */
zend_result proxy_class_name_minit(int type);
void proxy_class_name_mshutdown(void);

/* ---- shared helpers ---------------------------------------------------- */

ZEND_COLD void proxy_throw(zend_class_entry *ce, const char *format, ...) ZEND_ATTRIBUTE_FORMAT(printf, 2, 3);

static zend_always_inline proxy_class *proxy_class_from_handlers(const zend_object_handlers *h)
{
	return ((const proxy_handlers *) h)->cls;
}

/* The description of a generated class; ce->create_object == proxy_create_object. */
static zend_always_inline proxy_class *proxy_class_from_ce(const zend_class_entry *ce)
{
	return (proxy_class *) ((char *) ce - XtOffsetOf(proxy_class, ce_storage));
}

/* Identity test: every proxy handler table shares proxy_free_obj. Objects of
 * a generated class that the engine allocated itself (lazy ghosts/proxies made
 * through reflection) adopt the standard handlers the first time they are
 * seen, so this test is false for them afterwards. */
void proxy_free_obj(zend_object *obj);

static zend_always_inline bool proxy_is_proxy(const zend_object *obj)
{
	return obj->handlers->free_obj == proxy_free_obj;
}

/* Proxy state of obj, or NULL for an object without it (allocated behind the
 * class' allocator); such an object is switched to the standard handlers. */
proxy_object *proxy_object_from_obj(zend_object *obj);

static zend_always_inline zend_class_entry *proxy_type_of(const proxy_object *po)
{
	return po->target ? po->target->ce : po->cls->type_ce;
}

/* ---- proxy_class.c ----------------------------------------------------- */

zend_result proxy_class_validate_type(zend_class_entry *ce, bool for_wrap);
proxy_class *proxy_class_get(zend_class_entry *type_ce);
proxy_method *proxy_method_for_function(proxy_class *cls, zend_function *original,
	bool in_function_table);
zend_function *proxy_dynamic_trampoline(proxy_class *cls, zend_string *name);
bool proxy_function_is_trampoline(const zend_function *fn);

/* ---- proxy_object.c ---------------------------------------------------- */

void proxy_class_init_handlers(proxy_class *cls);
zend_object *proxy_create_object(zend_class_entry *ce);
void proxy_object_configure(proxy_object *po, zend_object *target,
	zval *method_interceptor, zval *property_interceptor);

/* ---- proxy_dispatch.c -------------------------------------------------- */

void proxy_dispatch_minit(void);

void ZEND_FASTCALL proxy_method_handler(INTERNAL_FUNCTION_PARAMETERS);
void ZEND_FASTCALL proxy_dynamic_method_handler(INTERNAL_FUNCTION_PARAMETERS);

/* Dispatches a call to an existing instance method through the interceptor,
 * e.g. for count(), __toString(), ArrayAccess and iteration. The
 * result is stored in retval (UNDEF when an exception was thrown). */
void proxy_dispatch_method(proxy_object *po, zend_object *proxy_obj, proxy_method *pm,
	uint32_t argc, zval *args, zval *retval);

bool proxy_has_property_interceptor(const proxy_object *po);

/* Runs a property operation through the interceptor. For GET the
 * result is stored in retval; for ISSET retval holds a bool; SET/UNSET
 * leave retval NULL. Returns FAILURE when an exception was thrown. */
zend_result proxy_dispatch_property(proxy_object *po, zend_object *proxy_obj,
	int op, zend_string *name, int mode, zval *value, zend_class_entry *scope, zval *retval);

/* GET variant reporting facts about the target operation (PROXY_GET_* flags). */
#define PROXY_GET_TARGET_NOTICE (1u << 0) /* target already emitted the writable-fetch notice */
#define PROXY_GET_UNSET_SLOT    (1u << 1) /* writable fetch found uninitialized target storage */
#define PROXY_GET_RV_ERROR      (1u << 2) /* target threw, but its return value must reach the VM */
#define PROXY_GET_ORIGINAL_RAN  (1u << 3) /* proceed() reached the target operation */
zend_result proxy_dispatch_property_get(proxy_object *po, zend_object *proxy_obj,
	zend_string *name, int mode, zend_class_entry *scope, zval *retval, uint32_t *flags);

zend_class_entry *proxy_current_scope(void);
zend_object *proxy_storage_object(proxy_object *po, bool init);

/* A real proxy that the engine turned into a lazy object cannot work: refuse
 * (throws) and drop the lazy state so that nothing is retained. */
bool proxy_refuse_lazy(zend_object *obj);
int proxy_enum_read(zend_object *obj, zend_string *name, zend_class_entry *scope, zval *dst);
typedef bool (*proxy_declared_cb)(void *ctx, zend_string *key, zend_property_info *info, zval *slot);
bool proxy_foreach_declared(proxy_object *po, zend_object *storage, proxy_declared_cb cb, void *ctx);

/* ---- proxy_iterator.c -------------------------------------------------- */

zend_object_iterator *proxy_get_iterator(zend_class_entry *ce, zval *object, int by_ref);

#endif /* PHP_PROXY_H */
