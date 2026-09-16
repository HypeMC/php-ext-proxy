/*
 * ext/proxy - object ::class resolution
 *
 * The VM reads ::class straight from the class entry, so object handlers cannot
 * change its result. Wrap dynamic ::class expressions during compilation while
 * retaining the native expression as the argument. The native opcode keeps its
 * operand evaluation, errors and lifetime semantics; the helper only translates
 * a generated class name. No execution or opcode hook is needed, preserving JIT.
 */

#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php_proxy.h"
#include "zend_ast.h"
#include "zend_system_id.h"

static zend_ast_process_t proxy_previous_ast_process;

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_proxy_class_name_resolve, 0, 1, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_proxy_class_name_construct, 0, 0, 0)
ZEND_END_ARG_INFO()

static ZEND_METHOD(Proxy_Internal_ClassName, __construct)
{
	ZEND_PARSE_PARAMETERS_NONE();
	zend_throw_error(NULL, "Proxy\\Internal\\ClassName is an internal compiler helper");
}

static ZEND_METHOD(Proxy_Internal_ClassName, resolve)
{
	zend_string *name;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_STR(name)
	ZEND_PARSE_PARAMETERS_END();

	/* Resolve only an already registered class; ::class must never autoload.
	 * create_object identifies our embedded class entry before recovering its
	 * containing description. This also covers unconfigured objects allocated
	 * by reflection: the translation describes the class, not object state. */
	zend_string *lcname = zend_string_tolower(name);
	zend_class_entry *ce = zend_hash_find_ptr(EG(class_table), lcname);
	zend_string_release(lcname);
	if (ce && ce->create_object == proxy_create_object) {
		RETURN_STR_COPY(proxy_class_from_ce(ce)->type_ce->name);
	}
	RETURN_STR_COPY(name);
}

static const zend_function_entry proxy_class_name_methods[] = {
	ZEND_ME(Proxy_Internal_ClassName, __construct, arginfo_proxy_class_name_construct, ZEND_ACC_PRIVATE)
	ZEND_ME(Proxy_Internal_ClassName, resolve, arginfo_proxy_class_name_resolve, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
	ZEND_FE_END
};

/* Defaults, constant values and attribute arguments retain their native AST.
 * A closure nested inside one of these expressions still has an ordinary
 * runtime body, handled separately by the declaration branch below. */
static bool proxy_class_name_child_is_constant(zend_ast_kind kind, uint32_t child)
{
	switch (kind) {
		case ZEND_AST_CONST_ELEM:
		case ZEND_AST_PROP_ELEM:
		case ZEND_AST_ENUM_CASE:
		case ZEND_AST_ATTRIBUTE:
			return child == 1;
		case ZEND_AST_PARAM:
			return child == 2;
		default:
			return false;
	}
}

static void proxy_class_name_wrap(zend_ast **ast_ptr)
{
	/* Keep the native operation as the argument: ClassName::resolve($expr::class). */
	zend_ast *ast = *ast_ptr;
	uint32_t lineno = zend_ast_get_lineno(ast);
	/* Constructors default to the parser's current line, which is already at
	 * EOF here. Zend takes the static-call opcode's line from the method-name
	 * ZVAL, so setting only call->lineno can put opcodes outside their function.
	 * Debuggers rely on every opcode fitting that function's source range. */
	zend_ast *class_name = zend_ast_create_zval_from_str(
		zend_string_init(ZEND_STRL("Proxy\\Internal\\ClassName"), 0));
	class_name->attr = ZEND_NAME_FQ;
	Z_LINENO_P(zend_ast_get_zval(class_name)) = lineno;
	zend_ast *method_name = zend_ast_create_zval_from_str(
		zend_string_init(ZEND_STRL("resolve"), 0));
	Z_LINENO_P(zend_ast_get_zval(method_name)) = lineno;
	zend_ast *args = zend_ast_create_list(1, ZEND_AST_ARG_LIST, ast);
	args->lineno = lineno;
	zend_ast *call = zend_ast_create(ZEND_AST_STATIC_CALL, class_name, method_name, args);
	call->lineno = lineno;
	*ast_ptr = call;
}

typedef enum {
	PROXY_AST_VISIT,
	PROXY_AST_WRAP,
} proxy_ast_action;

typedef struct {
	zend_ast **slot;              /* Parent's child pointer, replaced when wrapping. */
	bool constant_expression;
	proxy_ast_action action;
} proxy_ast_task;

typedef struct {
	proxy_ast_task *items;
	size_t count;
	size_t capacity;
} proxy_ast_stack;

static void proxy_class_name_push(proxy_ast_stack *stack, zend_ast **slot,
	bool constant_expression, proxy_ast_action action)
{
	if (!*slot) {
		return;
	}
	if (stack->count == stack->capacity) {
		/* safe_erealloc checks the multiplication before allocation. Updating
		 * the capacity afterwards is safe because successful allocation proves
		 * the doubled number of entries fits in size_t. */
		stack->items = safe_erealloc(stack->items, stack->capacity, 2 * sizeof(*stack->items), 0);
		stack->capacity *= 2;
	}
	stack->items[stack->count++] = (proxy_ast_task) { slot, constant_expression, action };
}

static void proxy_class_name_transform(zend_ast **ast_ptr)
{
	/* This hook runs before Zend's recursive compiler and its stack checks.
	 * Use a heap work list so deeply nested input reaches Zend's normal
	 * compilation limit instead of overflowing our own native call stack. */
	proxy_ast_stack stack = { .capacity = 64 };
	stack.items = safe_emalloc(stack.capacity, sizeof(*stack.items), 0);
	proxy_class_name_push(&stack, ast_ptr, false, PROXY_AST_VISIT);
	while (stack.count) {
		proxy_ast_task current = stack.items[--stack.count];
		zend_ast *ast = *current.slot;
		if (current.action == PROXY_AST_WRAP) {
			proxy_class_name_wrap(current.slot);
			continue;
		}
		/* zend_ast_is_decl() is only available from PHP 8.5; the layout and
		 * equivalent predicate below are shared with PHP 8.4. */
		if (zend_ast_is_special(ast) && ast->kind >= ZEND_AST_FUNC_DECL) {
			zend_ast_decl *decl = (zend_ast_decl *) ast;
			for (size_t i = sizeof(decl->child) / sizeof(decl->child[0]); i > 0; i--) {
				proxy_class_name_push(&stack, &decl->child[i - 1], false, PROXY_AST_VISIT);
			}
			continue;
		}
		if (zend_ast_is_list(ast)) {
			zend_ast_list *list = zend_ast_get_list(ast);
			for (uint32_t i = list->children; i > 0; i--) {
				proxy_class_name_push(&stack, &list->child[i - 1],
					current.constant_expression, PROXY_AST_VISIT);
			}
			continue;
		}
		if (zend_ast_is_special(ast)) {
			continue;
		}

		/* Named classes and self/parent/static are represented by ZVAL nodes
		 * and remain ordinary PHP class-level expressions. Schedule the wrap
		 * after the original children, never visiting the inserted helper. */
		if (!current.constant_expression && ast->kind == ZEND_AST_CLASS_NAME
				&& ast->child[0]->kind != ZEND_AST_ZVAL) {
			proxy_class_name_push(&stack, current.slot, false, PROXY_AST_WRAP);
		}
		for (uint32_t i = zend_ast_get_num_children(ast); i > 0; i--) {
			bool constant_expression = current.constant_expression
				|| proxy_class_name_child_is_constant(ast->kind, i - 1);
			proxy_class_name_push(&stack, &ast->child[i - 1], constant_expression, PROXY_AST_VISIT);
		}
	}
	efree(stack.items);
}

static void proxy_class_name_ast_process(zend_ast *ast)
{
	if (proxy_previous_ast_process) {
		proxy_previous_ast_process(ast);
	}
	/* The parser root is a statement list, so replacements happen in its
	 * children; no replacement of CG(ast) itself is necessary. */
	proxy_class_name_transform(&ast);
}

zend_result proxy_class_name_minit(int type)
{
	if (type != MODULE_PERSISTENT) {
		zend_error(E_CORE_WARNING,
			"proxy must be loaded at PHP startup; dl() cannot update already compiled ::class expressions");
		return FAILURE;
	}

	/* Partition OPcache's persistent file cache by this compiler transform.
	 * Bump the ABI token if the emitted helper or translation rules change. */
	static const char transform_abi[] = "class-name-ast-v2";
	if (zend_add_system_entropy("proxy", "class-name-ast",
			transform_abi, sizeof(transform_abi) - 1) == FAILURE) {
		zend_error(E_CORE_WARNING, "proxy must initialize its ::class compiler hook during PHP startup");
		return FAILURE;
	}

	zend_class_entry entry;
	INIT_NS_CLASS_ENTRY(entry, "Proxy\\Internal", "ClassName", proxy_class_name_methods);
	zend_class_entry *ce = zend_register_internal_class(&entry);
	ce->ce_flags |= ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES | ZEND_ACC_NOT_SERIALIZABLE;

	proxy_previous_ast_process = zend_ast_process;
	zend_ast_process = proxy_class_name_ast_process;
	return SUCCESS;
}

void proxy_class_name_mshutdown(void)
{
	if (zend_ast_process == proxy_class_name_ast_process) {
		zend_ast_process = proxy_previous_ast_process;
	}
}
