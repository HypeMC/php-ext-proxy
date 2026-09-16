/*
 * ext/proxy - iteration over proxy objects
 *
 * foreach over a proxy of an Iterator dispatches rewind()/valid()/current()/
 * key()/next() through the method interceptor; for IteratorAggregate the
 * getIterator() call is intercepted and iteration continues on its result.
 * Every other proxy is iterated by a property iterator that mirrors the
 * engine's enumeration of an object (declaration order, scope visibility,
 * hooks) while reading each property through the proxy: interceptors apply
 * and no engine code ever walks a table pointing into the target's storage.
 */

#include "php_proxy.h"
#include "zend_interfaces.h"

typedef struct _proxy_iterator {
	zend_object_iterator it;
	proxy_object *po;
	proxy_method *m_rewind;
	proxy_method *m_valid;
	proxy_method *m_current;
	proxy_method *m_key;
	proxy_method *m_next;
	zval current;
} proxy_iterator;

static void proxy_it_invalidate_current(zend_object_iterator *iter)
{
	proxy_iterator *pit = (proxy_iterator *) iter;
	if (!Z_ISUNDEF(pit->current)) {
		zval_ptr_dtor(&pit->current);
		ZVAL_UNDEF(&pit->current);
	}
}

static void proxy_it_dtor(zend_object_iterator *iter)
{
	proxy_iterator *pit = (proxy_iterator *) iter;
	proxy_it_invalidate_current(iter);
	zval_ptr_dtor(&pit->it.data);
}

static zend_result proxy_it_valid(zend_object_iterator *iter)
{
	proxy_iterator *pit = (proxy_iterator *) iter;
	zval result;
	proxy_dispatch_method(pit->po, Z_OBJ(pit->it.data), pit->m_valid, 0, NULL, &result);
	if (Z_ISUNDEF(result)) {
		return FAILURE;
	}
	bool valid = zend_is_true(&result);
	zval_ptr_dtor(&result);
	return valid ? SUCCESS : FAILURE;
}

static zval *proxy_it_get_current_data(zend_object_iterator *iter)
{
	proxy_iterator *pit = (proxy_iterator *) iter;
	if (Z_ISUNDEF(pit->current)) {
		proxy_dispatch_method(pit->po, Z_OBJ(pit->it.data), pit->m_current, 0, NULL, &pit->current);
		if (Z_ISUNDEF(pit->current)) {
			return NULL;
		}
	}
	return &pit->current;
}

static void proxy_it_get_current_key(zend_object_iterator *iter, zval *key)
{
	proxy_iterator *pit = (proxy_iterator *) iter;
	zval result;
	proxy_dispatch_method(pit->po, Z_OBJ(pit->it.data), pit->m_key, 0, NULL, &result);
	if (Z_ISUNDEF(result)) {
		ZVAL_NULL(key);
		return;
	}
	if (Z_ISREF(result)) {
		ZVAL_COPY(key, Z_REFVAL(result));
		zval_ptr_dtor(&result);
	} else {
		ZVAL_COPY_VALUE(key, &result);
	}
}

static void proxy_it_move_forward(zend_object_iterator *iter)
{
	proxy_iterator *pit = (proxy_iterator *) iter;
	zval result;
	proxy_it_invalidate_current(iter);
	proxy_dispatch_method(pit->po, Z_OBJ(pit->it.data), pit->m_next, 0, NULL, &result);
	zval_ptr_dtor(&result);
}

static void proxy_it_rewind(zend_object_iterator *iter)
{
	proxy_iterator *pit = (proxy_iterator *) iter;
	zval result;
	proxy_it_invalidate_current(iter);
	proxy_dispatch_method(pit->po, Z_OBJ(pit->it.data), pit->m_rewind, 0, NULL, &result);
	zval_ptr_dtor(&result);
}

static HashTable *proxy_it_get_gc(zend_object_iterator *iter, zval **table, int *n)
{
	proxy_iterator *pit = (proxy_iterator *) iter;
	zend_get_gc_buffer *buf = zend_get_gc_buffer_create();
	zend_get_gc_buffer_add_zval(buf, &pit->it.data);
	zend_get_gc_buffer_add_zval(buf, &pit->current);
	zend_get_gc_buffer_use(buf, table, n);
	return NULL;
}

static const zend_object_iterator_funcs proxy_iterator_funcs = {
	proxy_it_dtor,
	proxy_it_valid,
	proxy_it_get_current_data,
	proxy_it_get_current_key,
	proxy_it_move_forward,
	proxy_it_rewind,
	proxy_it_invalidate_current,
	proxy_it_get_gc,
};

static proxy_method *proxy_iterator_method(proxy_class *cls, const char *name, size_t len)
{
	zend_function *fn = zend_hash_str_find_ptr(&cls->ce->function_table, name, len);
	if (fn && proxy_function_is_trampoline(fn)) {
		return (proxy_method *) fn;
	}
	return NULL;
}

/* ------------------------------------------------------------------------- */
/* Property iterator (plain objects)                                         */
/* ------------------------------------------------------------------------- */

typedef struct _proxy_prop_iterator {
	zend_object_iterator it;
	proxy_object *po;
	HashTable *declared;          /* key => IS_PTR property info, in enumeration order */
	HashPosition declared_pos;
	uint32_t dynamic_it;          /* target hash iterator, or UINT32_MAX when absent */
	bool declared_done;
	bool dynamic_done;
	bool by_ref;
	zval current_key;
	zval current_data;
} proxy_prop_iterator;

static void proxy_pit_clear_current(proxy_prop_iterator *pit)
{
	zval_ptr_dtor(&pit->current_data);
	ZVAL_UNDEF(&pit->current_data);
	zval_ptr_dtor_nogc(&pit->current_key);
	ZVAL_UNDEF(&pit->current_key);
}

static bool proxy_pit_collect_declared(void *ctx, zend_string *key, zend_property_info *info, zval *slot)
{
	proxy_prop_iterator *pit = ctx;
	/* Visibility is decided once, for the scope of the foreach, as the
	 * engine's hooked-object iterator does. */
	if (zend_check_property_access(Z_OBJ(pit->it.data), key, false) == SUCCESS) {
		zend_hash_update_ptr(pit->declared, key, info);
	}
	return true;
}

static HashTable *proxy_pit_dynamic_table(proxy_prop_iterator *pit)
{
	zend_object *storage = proxy_storage_object(pit->po, false);
	if (!storage) {
		return NULL;
	}
	return storage->handlers->get_properties(storage);
}

/* Fetches the current element by reading through the proxy. A by-reference
 * fetch performs a writable read: the handler returns the target's storage
 * unless an interceptor replaced the value. */
static void proxy_pit_read(proxy_prop_iterator *pit, zend_string *name, zend_class_entry *scope)
{
	zend_object *obj = Z_OBJ(pit->it.data);

	if (!pit->by_ref) {
		zval value;
		int rc = proxy_enum_read(obj, name, scope, &value);
		if (rc > 0) {
			ZVAL_COPY_VALUE(&pit->current_data, &value);
		}
		return;
	}

	zval rv;
	const zend_class_entry *old_scope = EG(fake_scope);
	EG(fake_scope) = scope;
	zval *ptr = obj->handlers->read_property(obj, name, BP_VAR_W, NULL, &rv);
	EG(fake_scope) = (zend_class_entry *) old_scope;
	if (EG(exception)) {
		if (ptr == &rv) {
			zval_ptr_dtor(&rv);
		}
		return;
	}
	if (ptr == &EG(uninitialized_zval) || ptr == &EG(error_zval)) {
		return;
	}
	if (ptr != &rv) {
		/* target storage: alias it, with the property's type constraint */
		if (Z_TYPE_P(ptr) == IS_UNDEF) {
			return;
		}
		if (!Z_ISREF_P(ptr)) {
			zend_object *storage = proxy_storage_object(pit->po, false);
			ZVAL_MAKE_REF(ptr);
			if (storage && ptr >= storage->properties_table
					&& ptr < storage->properties_table + storage->ce->default_properties_count) {
				zend_property_info *info = zend_get_typed_property_info_for_slot(storage, ptr);
				if (info) {
					ZEND_REF_ADD_TYPE_SOURCE(Z_REF_P(ptr), info);
				}
			}
		}
		ZVAL_COPY(&pit->current_data, ptr);
		return;
	}
	/* an owned value: the VM wraps it in a temporary reference */
	ZVAL_COPY_VALUE(&pit->current_data, &rv);
}

static void proxy_pit_fetch_declared(proxy_prop_iterator *pit)
{
	zval *entry = zend_hash_get_current_data_ex(pit->declared, &pit->declared_pos);
	if (!entry) {
		pit->declared_done = true;
		return;
	}
	zend_property_info *info = Z_PTR_P(entry);
	if (info->hooks) {
		zend_function *get = info->hooks[ZEND_PROPERTY_HOOK_GET];
		if (!get && (info->flags & ZEND_ACC_VIRTUAL)) {
			return;
		}
		if (pit->by_ref && (!get || !(get->common.fn_flags & ZEND_ACC_RETURN_REFERENCE))) {
			zend_throw_error(NULL, "Cannot create reference to property %s::$%s",
				ZSTR_VAL(pit->po->cls->type_ce->name), zend_get_unmangled_property_name(info->name));
			return;
		}
	} else {
		zend_object *storage = proxy_storage_object(pit->po, false);
		if (storage) {
			zval *slot = OBJ_PROP(storage, info->offset);
			if (Z_TYPE_P(slot) == IS_UNDEF) {
				return;
			}
			if (pit->by_ref && (info->flags & ZEND_ACC_READONLY) && !Z_ISREF_P(slot)) {
				zend_throw_error(NULL, "Cannot acquire reference to readonly property %s::$%s",
					ZSTR_VAL(info->ce->name), zend_get_unmangled_property_name(info->name));
				return;
			}
		}
	}
	const char *plain = zend_get_unmangled_property_name(info->name);
	zend_string *name = zend_string_init(plain, strlen(plain), 0);
	proxy_pit_read(pit, name, info->ce);
	if (!Z_ISUNDEF(pit->current_data)) {
		ZVAL_STR(&pit->current_key, name);
	} else {
		zend_string_release(name);
	}
}

static void proxy_pit_fetch_dynamic(proxy_prop_iterator *pit)
{
	HashTable *props = proxy_pit_dynamic_table(pit);
	if (!props) {
		pit->dynamic_done = true;
		return;
	}
	HashPosition pos = zend_hash_iterator_pos(pit->dynamic_it, props);
	if (pos >= props->nNumUsed) {
		pit->dynamic_done = true;
		return;
	}
	Bucket *bucket = props->arData + pos;
	if (Z_TYPE(bucket->val) == IS_UNDEF || Z_TYPE(bucket->val) == IS_INDIRECT) {
		return;
	}
	if (bucket->key && zend_check_property_access(Z_OBJ(pit->it.data), bucket->key, true) != SUCCESS) {
		return;
	}
	/* The read below runs user code that may modify (and reallocate) the
	 * target's table: take everything needed from the bucket first. */
	zend_string *key = bucket->key ? zend_string_copy(bucket->key) : NULL;
	zend_long h = (zend_long) bucket->h;
	zend_string *name = key ? zend_string_copy(key) : zend_long_to_str(h);
	proxy_pit_read(pit, name, NULL);
	if (!Z_ISUNDEF(pit->current_data)) {
		if (key) {
			ZVAL_STR(&pit->current_key, name);
		} else {
			ZVAL_LONG(&pit->current_key, h);
			zend_string_release(name);
		}
	} else {
		zend_string_release(name);
	}
	if (key) {
		zend_string_release(key);
	}
}

static void proxy_pit_move_forward(zend_object_iterator *iter);

static void proxy_pit_fetch_current(proxy_prop_iterator *pit)
{
	if (!Z_ISUNDEF(pit->current_data)) {
		return;
	}
	while (true) {
		bool declared_done = pit->declared_done;
		bool dynamic_done = pit->dynamic_done;
		if (!declared_done) {
			proxy_pit_fetch_declared(pit);
		} else if (!dynamic_done) {
			proxy_pit_fetch_dynamic(pit);
		} else {
			break;
		}
		if (!Z_ISUNDEF(pit->current_data) || EG(exception)) {
			break;
		}
		if (declared_done != pit->declared_done || dynamic_done != pit->dynamic_done) {
			/* a section ended: the next section starts at its first element */
			continue;
		}
		proxy_pit_move_forward(&pit->it);
	}
}

static void proxy_pit_dtor(zend_object_iterator *iter)
{
	proxy_prop_iterator *pit = (proxy_prop_iterator *) iter;
	proxy_pit_clear_current(pit);
	if (pit->dynamic_it != UINT32_MAX) {
		zend_hash_iterator_del(pit->dynamic_it);
	}
	zend_array_destroy(pit->declared);
	zval_ptr_dtor(&pit->it.data);
}

static zend_result proxy_pit_valid(zend_object_iterator *iter)
{
	proxy_prop_iterator *pit = (proxy_prop_iterator *) iter;
	proxy_pit_fetch_current(pit);
	return Z_ISUNDEF(pit->current_data) ? FAILURE : SUCCESS;
}

static zval *proxy_pit_get_current_data(zend_object_iterator *iter)
{
	proxy_prop_iterator *pit = (proxy_prop_iterator *) iter;
	proxy_pit_fetch_current(pit);
	return Z_ISUNDEF(pit->current_data) ? NULL : &pit->current_data;
}

static void proxy_pit_get_current_key(zend_object_iterator *iter, zval *key)
{
	proxy_prop_iterator *pit = (proxy_prop_iterator *) iter;
	proxy_pit_fetch_current(pit);
	if (Z_ISUNDEF(pit->current_key)) {
		ZVAL_NULL(key);
	} else {
		ZVAL_COPY(key, &pit->current_key);
	}
}

static void proxy_pit_move_forward(zend_object_iterator *iter)
{
	proxy_prop_iterator *pit = (proxy_prop_iterator *) iter;
	proxy_pit_clear_current(pit);
	if (!pit->declared_done) {
		if (zend_hash_move_forward_ex(pit->declared, &pit->declared_pos) == FAILURE
				|| !zend_hash_get_current_data_ex(pit->declared, &pit->declared_pos)) {
			pit->declared_done = true;
		}
		return;
	}
	if (!pit->dynamic_done) {
		HashTable *props = proxy_pit_dynamic_table(pit);
		if (!props) {
			pit->dynamic_done = true;
			return;
		}
		HashPosition pos = zend_hash_iterator_pos(pit->dynamic_it, props);
		EG(ht_iterators)[pit->dynamic_it].pos = pos + 1;
	}
}

static void proxy_pit_rewind(zend_object_iterator *iter)
{
	proxy_prop_iterator *pit = (proxy_prop_iterator *) iter;
	proxy_pit_clear_current(pit);
	zend_hash_internal_pointer_reset_ex(pit->declared, &pit->declared_pos);
	pit->declared_done = zend_hash_num_elements(pit->declared) == 0;
	HashTable *props = proxy_pit_dynamic_table(pit);
	if (props) {
		if (pit->dynamic_it == UINT32_MAX) {
			pit->dynamic_it = zend_hash_iterator_add(props, 0);
		} else {
			zend_hash_iterator_pos(pit->dynamic_it, props);
			EG(ht_iterators)[pit->dynamic_it].pos = 0;
		}
		pit->dynamic_done = false;
	} else {
		pit->dynamic_done = true;
	}
}

static HashTable *proxy_pit_get_gc(zend_object_iterator *iter, zval **table, int *n)
{
	proxy_prop_iterator *pit = (proxy_prop_iterator *) iter;
	zend_get_gc_buffer *buf = zend_get_gc_buffer_create();
	zend_get_gc_buffer_add_zval(buf, &pit->it.data);
	zend_get_gc_buffer_add_zval(buf, &pit->current_data);
	zend_get_gc_buffer_use(buf, table, n);
	return NULL;
}

static const zend_object_iterator_funcs proxy_prop_iterator_funcs = {
	proxy_pit_dtor,
	proxy_pit_valid,
	proxy_pit_get_current_data,
	proxy_pit_get_current_key,
	proxy_pit_move_forward,
	proxy_pit_rewind,
	NULL,
	proxy_pit_get_gc,
};

static zend_object_iterator *proxy_property_iterator(proxy_object *po, zend_object *obj, int by_ref)
{
	proxy_prop_iterator *pit = emalloc(sizeof(proxy_prop_iterator));
	zend_iterator_init(&pit->it);
	/* hold the proxy before any user code (a lazy initializer, interceptors)
	 * can release the variable being iterated */
	ZVAL_OBJ_COPY(&pit->it.data, obj);
	pit->it.funcs = &proxy_prop_iterator_funcs;
	pit->po = po;
	pit->by_ref = by_ref != 0;
	pit->declared = zend_new_array(obj->ce->default_properties_count);
	pit->declared_pos = 0;
	pit->dynamic_it = UINT32_MAX;
	pit->declared_done = true;
	pit->dynamic_done = true;
	ZVAL_UNDEF(&pit->current_key);
	ZVAL_UNDEF(&pit->current_data);

	/* PHP initializes a lazy object before iterating it */
	zend_object *storage = proxy_storage_object(po, true);
	if (EG(exception)) {
		return &pit->it;
	}
	proxy_foreach_declared(po, storage, proxy_pit_collect_declared, pit);
	return &pit->it;
}

/* Property iteration of a plain object exists only for foreach. The engine
 * decides from the presence of get_iterator whether spreads and `yield from`
 * accept an object; a proxy of a non-Traversable type must fail like the
 * type itself does. */
static bool proxy_iteration_refused(zend_class_entry *ce)
{
	zend_execute_data *execute_data = EG(current_execute_data);
	if (!execute_data || !execute_data->func || !ZEND_USER_CODE(execute_data->func->type) || !execute_data->opline) {
		return false;
	}
	switch (execute_data->opline->opcode) {
		case ZEND_YIELD_FROM:
			zend_throw_error(NULL, "Can use \"yield from\" only with arrays and Traversables");
			return true;
		case ZEND_ADD_ARRAY_UNPACK:
		case ZEND_SEND_UNPACK:
			zend_type_error("Only arrays and Traversables can be unpacked, %s given", ZSTR_VAL(ce->name));
			return true;
		default:
			return false;
	}
}

static zend_object_iterator *proxy_method_iterator(proxy_class *cls, proxy_object *po, zend_object *obj)
{
	proxy_iterator *iterator = emalloc(sizeof(proxy_iterator));
	zend_iterator_init(&iterator->it);
	ZVAL_OBJ_COPY(&iterator->it.data, obj);
	iterator->it.funcs = &proxy_iterator_funcs;
	iterator->po = po;
	iterator->m_rewind = proxy_iterator_method(cls, ZEND_STRL("rewind"));
	iterator->m_valid = proxy_iterator_method(cls, ZEND_STRL("valid"));
	iterator->m_current = proxy_iterator_method(cls, ZEND_STRL("current"));
	iterator->m_key = proxy_iterator_method(cls, ZEND_STRL("key"));
	iterator->m_next = proxy_iterator_method(cls, ZEND_STRL("next"));
	ZVAL_UNDEF(&iterator->current);
	ZEND_ASSERT(iterator->m_rewind && iterator->m_valid && iterator->m_current
		&& iterator->m_key && iterator->m_next);
	return &iterator->it;
}

static zend_object_iterator *proxy_aggregate_iterator(
		proxy_class *cls, proxy_object *po, zend_object *obj, int by_ref)
{
	proxy_method *get_iterator = proxy_iterator_method(cls, ZEND_STRL("getiterator"));
	zend_class_entry *type_ce = proxy_type_of(po);
	zval traversable;
	ZEND_ASSERT(get_iterator);

	/* Keep the proxy alive while its callback and the returned object's
	 * iterator factory run: either may release the caller's last reference. */
	GC_ADDREF(obj);
	proxy_dispatch_method(po, obj, get_iterator, 0, NULL, &traversable);
	if (Z_ISUNDEF(traversable)) {
		OBJ_RELEASE(obj);
		return NULL;
	}
	zend_class_entry *iterator_ce = Z_TYPE(traversable) == IS_OBJECT ? Z_OBJCE(traversable) : NULL;
	if (!iterator_ce || !iterator_ce->get_iterator || Z_OBJ(traversable) == obj) {
		if (!EG(exception)) {
			zend_throw_exception_ex(NULL, 0,
				"Objects returned by %s::getIterator() must be traversable or implement interface Iterator",
				ZSTR_VAL(type_ce->name));
		}
		zval_ptr_dtor(&traversable);
		OBJ_RELEASE(obj);
		return NULL;
	}
	zend_object_iterator *iterator = iterator_ce->get_iterator(iterator_ce, &traversable, by_ref);
	zval_ptr_dtor(&traversable);
	OBJ_RELEASE(obj);
	return iterator;
}

zend_object_iterator *proxy_get_iterator(zend_class_entry *ce, zval *object, int by_ref)
{
	zend_object *obj = Z_OBJ_P(object);
	bool is_proxy = proxy_is_proxy(obj);
	proxy_object *po = is_proxy ? proxy_object_from_obj(obj) : NULL;

	if (UNEXPECTED(!po)) {
		/* a plain object of the generated class (allocated by the engine):
		 * iterate it the way PHP iterates any object */
		if (!is_proxy && !instanceof_function(ce, zend_ce_traversable)) {
			if (proxy_iteration_refused(ce)) {
				return NULL;
			}
			return zend_hooked_object_get_iterator(ce, object, by_ref);
		}
		proxy_throw(proxy_ce_UnsupportedOperation, "Proxy object of class %s was not created through Proxy\\Proxy::mock() or Proxy\\Proxy::wrap()", ZSTR_VAL(ce->name));
		return NULL;
	}
	proxy_class *cls = proxy_class_from_handlers(obj->handlers);

	if (!po->initialized) {
		proxy_throw(proxy_ce_UnsupportedOperation, "Proxy object of class %s was not created through Proxy\\Proxy::mock() or Proxy\\Proxy::wrap()", ZSTR_VAL(ce->name));
		return NULL;
	}
	if (UNEXPECTED(zend_object_is_lazy(obj)) && proxy_refuse_lazy(obj)) {
		return NULL;
	}

	if (instanceof_function(ce, zend_ce_iterator)) {
		if (by_ref) {
			if (po->target && po->target->ce->get_iterator && po->target->ce->get_iterator != zend_user_it_get_new_iterator) {
				/* an internal iterator (ArrayIterator, ...) supports by-reference
				 * iteration itself; its methods are not intercepted here */
				zval target;
				ZVAL_OBJ(&target, po->target);
				return po->target->ce->get_iterator(po->target->ce, &target, by_ref);
			}
			zend_throw_error(NULL, "An iterator cannot be used with foreach by reference");
			return NULL;
		}
		return proxy_method_iterator(cls, po, obj);
	}

	if (instanceof_function(ce, zend_ce_aggregate)) {
		return proxy_aggregate_iterator(cls, po, obj, by_ref);
	}

	/* Internal Traversable implementation: iterate the wrapped target. */
	if (instanceof_function(ce, zend_ce_traversable)) {
		if (po->target && po->target->ce->get_iterator) {
			zval target;
			ZVAL_OBJ(&target, po->target);
			return po->target->ce->get_iterator(po->target->ce, &target, by_ref);
		}
		proxy_throw(proxy_ce_UnsupportedOperation, "Proxy of %s cannot be iterated without a backing object", ZSTR_VAL(proxy_type_of(po)->name));
		return NULL;
	}

	/* PHP lets spreads and `yield from` iterate objects of classes with
	 * property hooks (their class carries the hooked-object iterator) */
	if (proxy_type_of(po)->get_iterator != zend_hooked_object_get_iterator && proxy_iteration_refused(ce)) {
		return NULL;
	}
	return proxy_property_iterator(po, obj, by_ref);
}
