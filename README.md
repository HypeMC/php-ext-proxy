# ext-proxy

Engine-level proxy objects for PHP 8.4 and 8.5.

`Proxy::mock()` creates a strict fake of a class, abstract class or interface;
`Proxy::wrap()` wraps an existing object. Both intercept instance method calls
and property operations through method and property interceptors, and both
work on user-defined `final` classes and `final` methods without touching the
proxied class. Internal final classes and engine-restricted interfaces are
explicitly excluded; the supported-type boundary is described below.

```php
use Proxy\Invocation;
use Proxy\Proxy;

$client = Proxy::wrap(
    new HttpClient(),
    methodInterceptor: function (Invocation $call): mixed {
        $start = hrtime(true);
        try {
            if (strtolower($call->method()) === 'send'
                && str_contains($call->arg(0), 'test')) {
                return new FakeResponse(200);
            }
            return $call->proceed(...$call->args());
        } finally {
            log($call->method(), hrtime(true) - $start);
        }
    },
);

$client instanceof HttpClient; // true
$client::class;               // HttpClient
$client->send('/test');        // FakeResponse, the real send() never runs
$client->getTimeout();         // delegated to the wrapped HttpClient
```

## Building

```sh
phpize
./configure --enable-proxy
make
make test
```

Requires PHP >= 8.4 (property hooks and asymmetric visibility). NTS and ZTS
builds are supported.

Load the extension at PHP startup with `extension=proxy.so` in `php.ini`, or
`php -n -d extension=/path/to/modules/proxy.so script.php` when testing a local
build. Loading it later through `dl()` is rejected: object `::class` expressions
must be compiled with the extension already active.

## API

Everything lives in the `Proxy` namespace: `Proxy\Proxy` (the facade),
`Proxy\Invocation`, `Proxy\PropertyInvocation`, `Proxy\PropertyOperation` and
the exceptions in `Proxy\Exception`.

```php
namespace Proxy;

final class Proxy
{
    public static function mock(
        string $class,
        ?callable $methodInterceptor = null,
        ?callable $propertyInterceptor = null,
    ): object;

    public static function wrap(
        object $target,
        ?callable $methodInterceptor = null,
        ?callable $propertyInterceptor = null,
    ): object;

    public static function isProxy(object $object): bool;
    public static function target(object $proxy): ?object;  // wrapped object, null for mocks
}
```

With `use Proxy\Proxy;` call sites read `Proxy::mock(...)` / `Proxy::wrap(...)`.
Both factories accept only the type or target and the two optional interceptors.
Route member-specific behavior inside those callbacks; there are no `methods`
or `properties` registration maps. `propertyInterceptor` is the third positional
argument.

`proxy.stub.php` doubles as the stub for static analysis. Both factories are
generic: `Proxy::mock(HttpClient::class)` and `Proxy::wrap($client)` are typed
as `HttpClient` for PHPStan, Psalm and PhpStorm (`@template T of object`,
`class-string<T>` / `T` parameter, `@return T`). The interceptors carry their
callable signatures: method callbacks receive
`Invocation`, and property callbacks receive `PropertyInvocation`.
Use `$proxy::class` to retrieve the original proxied type's name.

### Method interception

Resolution order for every instance method call:

```
methodInterceptor  →  original method (wrap) | UnconfiguredMethod (mock)
```

* `methodInterceptor` receives only the `Proxy\Invocation`. Select behavior
  using `$call->method()` and read arguments through `$call->args()`; positional
  arguments have integer keys and variadic named arguments retain their string
  keys. Compare method names case-insensitively when routing, as in PHP.
* `Invocation::proceed(...$args)` calls the target method using exactly
  the arguments passed to it (`proceed()` means zero arguments). It may be
  called any number of times; every call reaches the target without invoking
  the interceptor again. On a mock it throws `UnconfiguredMethod`.
  References are preserved: arguments received by
  reference stay references in `args()`, and `proceed(...$call->args())`
  forwards them by reference again. `proceed()` returns by reference so that
  reference-returning methods stay intact when interceptors are declared as
  `function &(Invocation $call)` or `fn &(Invocation $call)`. Every forwarding
  callback must return by reference when reference identity matters; a callback
  returning by value follows PHP's ordinary value-return semantics.
* Named arguments are mapped onto the proxied method's parameters at the call
  site: a gap before a later named argument is filled with the parameter's
  default value before interceptors run, trailing omitted optional parameters
  are absent from `args()` (as in `func_get_args()`); unknown named arguments
  are collected for variadic methods and rejected otherwise, exactly like a
  direct call. Typed variadic arguments are checked and coerced before any
  interceptor, including arguments supplied by name, and a violation names the
  argument's real position.
* Method names that resolve to `__call()` on the proxied type are interceptable
  under their own name; proceeding invokes the target's `__call()`. This
  includes first-class callables and `Closure::fromCallable([$proxy, 'name'])`
  of such names.
* Callables of the deprecated form `[$proxy, 'parent::method']` resolve
  `parent` against the generated class, so they run the proxied class' own
  method (uninstrumented) rather than its parent's.
* Method selection follows PHP exactly, including the private-shadow rule: a
  call made from an ancestor's scope selects the ancestor's private method
  rather than a descendant's redeclaration. Direct calls, first-class callables
  and reflection of the generated class dispatch the selected method through
  the chain. Callables that the engine resolves by itself (`[$proxy, 'method']`
  handed to `call_user_func()`, `array_map()`, `usort()`, iterators, ...) and
  `Closure::fromCallable()` select the same method, but when that method is a
  shadowed private ancestor method they call the ancestor's original function
  directly, on the proxy object and without interception. This narrow exclusion
  is approved to preserve JIT, alongside the original-class reflection exclusion
  (below). Even a strict mock can execute such a body; configured method
  interceptors do not run. Property operations in the body still use the proxy's
  handlers, so unconfigured properties raise `UnconfiguredProperty` on a mock.
  Where the call site can be changed, use `$proxy->method($arg)` or a first-class
  callable (`$callback = $proxy->method(...); $callback();`) from the authorized
  scope to retain interception.

PHP receives exactly one argument for each interceptor; additional required
callback parameters raise the usual
`ArgumentCountError`. An argument named like the callback's own parameter is
passed on without conflict.

Interceptor callables are resolved to closures at registration. A private or
protected method registered from an authorized scope remains callable when the
proxy is later used outside that scope; bound objects remain alive with their
interceptors.

```php
final class Proxy\Invocation
{
    public function method(): string;
    public function args(): array;
    public function arg(int $index): mixed;
    public function proxy(): object;
    public function target(): ?object;
    public function class(): string;      // the original proxied type, as in $proxy::class
    public function hasOriginal(): bool;
    public function &proceed(mixed ...$args): mixed;
}
```

### Property interception

```
propertyInterceptor  →  target operation (wrap) | UnconfiguredProperty (mock)
```

`propertyInterceptor` receives a `Proxy\PropertyInvocation`. One callable handles
all properties and all four operations. Select a property using its
case-sensitive name:

```php
propertyInterceptor: function (PropertyInvocation $prop): mixed {
    if ($prop->property() === 'name') {
        return match ($prop->operation()) {
            PropertyOperation::GET   => 'Fake ' . $prop->proceed(),
            PropertyOperation::SET   => $prop->proceed(strtoupper($prop->value())),
            PropertyOperation::ISSET => $prop->proceed(),
            PropertyOperation::UNSET => $prop->proceed(),
        };
    }
    return $prop->operation() === PropertyOperation::SET
        ? $prop->proceed($prop->value())
        : $prop->proceed();
}
```

`proceed()` takes no argument for `GET`, `ISSET` and `UNSET` and exactly one
argument (the value to write) for `SET`. It returns by reference, preserving
native `&get` hook references. Every call proceeds directly to the target;
on a mock it throws `UnconfiguredProperty`. The callback must also return by
reference to forward reference identity:

```php
propertyInterceptor: function &(PropertyInvocation $prop): mixed {
    if ($prop->operation() === PropertyOperation::SET) {
        return $prop->proceed($prop->value());
    }
    return $prop->proceed();
}
```

For a GET callback, the equivalent arrow form is
`fn &(PropertyInvocation $prop) => $prop->proceed()`. A value-returning callback
detaches a native hook's reference using ordinary PHP semantics. Native hook
restrictions still apply: a value-returning `get` cannot supply a writable
reference, and a backed property cannot combine `&get` with `set`.

Writable fetches through a forwarding chain reach the target's storage:
`$proxy->x = &$v`, `$proxy->list[] = $x`, `$ref = &$proxy->x`, `$proxy->obj->y = 1`
and `unset($proxy->arr[0])` behave as on the target, including PHP's
initialization rules for uninitialized typed properties (`unset()` of an offset
stays a no-op, a nested write reports the null object, a by-reference fetch of a
non-nullable property is rejected). This holds for `fn &` forwarders returning
`$prop->proceed()` directly and for value-returning forwarders alike. A
`function &` callback that copies the result into a local variable and returns
that variable returns a reference to the copy, as PHP's reference rules say:
writes land in the copy and `$proxy->x = &$v` fails with PHP's "Cannot assign
by reference to overloaded object". A reference to other storage (another
property, a static variable) selects that storage.

Interception follows PHP's own property resolution: an operation PHP would
reject on the proxied type (inaccessible property without the matching magic
method, write to a readonly class' undeclared property, `protected(set)`
violation, write to or indirect modification of a readonly property, or an
unavailable hook operation) fails before any interceptor runs, with PHP's
message and the caller's scope. The checks run in PHP's order: set visibility is
decided first (for an uninitialized lazy target without running its
initializer), then the readonly and magic-method decisions inspect the storage
PHP would inspect, the real instance behind a lazy target, initialized at that
point exactly like a direct write initializes it. An explicitly `unset()`
readonly slot of a class with `__set()`/`__unset()` dispatches to the magic
method, as in PHP. Read results are checked against the
declared property type. Writes are checked against the set hook's parameter when
a setter exists (a violation raises the hook's own argument `TypeError`);
otherwise they use the declared property type. Weak-mode coercion applies.
`$proxy->n++` past the integer range raises PHP's increment/decrement error.
`proceed()` performs the target operation with the visibility rules of the
original access. Conditional reads preserve the uninitialized result used by
`??` unless middleware initialized the property meanwhile, and `empty()`
evaluates `__isset()` and getters exactly once, also for hooked properties of
classes with internal ancestry.

`ReflectionProperty::getRawValue()`/`setRawValue()` on a proxy read and write
the target's backing value without hooks and without interceptors.

```php
final class Proxy\PropertyInvocation
{
    public function property(): string;
    public function operation(): Proxy\PropertyOperation; // GET | SET | ISSET | UNSET
    public function value(): mixed;                         // the value for SET
    public function proxy(): object;
    public function target(): ?object;
    public function class(): string;
    public function hasOriginal(): bool;
    public function &proceed(mixed ...$args): mixed;
}
```

### What else is intercepted

The following engine entry points go through the same chain:
`count()` (Countable), `$proxy[...]` (ArrayAccess),
`foreach` (Iterator / IteratorAggregate), string casts (`__toString`),
`$proxy(...)` (`__invoke`), `json_encode()` (JsonSerializable), callables such
as `[$proxy, 'method']`, `Closure::fromCallable()`, first-class callable syntax
and `ReflectionMethod::invoke()` when the method is reflected from the proxy's
generated class. Calls reflected from the original class are explicitly outside
interception, as described below.

Property enumeration follows PHP's own rules. The operations for which PHP
runs get hooks read every property through the property chain: `foreach` over a
plain proxy (by value and by reference), `get_object_vars()`, `json_encode()`
and `var_export()`. They see the same keys as on the target: declaration order,
visibility of the calling scope, virtual hooked properties, dynamic properties
(including ones added during iteration) and no uninitialized typed slots. Each
property is read exactly once, dynamic values are captured before declared
properties are read (PHP's order for hooked objects), and the consumer's own
recursion guard applies to the reads, so a proxy that is reachable from itself
reports `json_encode()`'s recursion error like a plain object. A strict mock
enumerates its declared properties and raises `UnconfiguredProperty` for the
first one no interceptor answers. Raw views never run interceptors or hooks:
`var_dump()`, `print_r()`, `debug_zval_dump()` and `(array)` show the target's
storage (typed uninitialized slots print as `uninitialized(type)`, also for an
uninitialized lazy target). Classes with their own property views, such as
`ArrayObject`, keep them. `var_export()` of a proxied `stdClass` uses the
generated class' `__set_state()` form, and dumps carry no `lazy ghost` marker,
because the object being dumped is the proxy.

Internal functions that operate on an object's properties table directly
(`array_walk()`, `http_build_query()`, `get_mangled_object_vars()`,
`ReflectionObject::getProperties()`) see the target's declared and dynamic
properties with PHP's visibility rules. Read-only consumers use a snapshot of
the target's storage that is brought up to date whenever it is fetched again.
`array_walk()` and `array_walk_recursive()` receive references to target
storage, so callback writes reach the target and retain its property type
constraints. These raw operations do not run property interceptors or hooks,
as with native property-table access. Walking continues when callbacks add or
remove properties. A lazy target is initialized by these calls (PHP's
`get_mangled_object_vars()` would not), and walking a proxy costs time
proportional to the square of its property count. The snapshot holds copies
of the target's values until the proxy is used again or released. A raw array
captured before walking a dynamic object remains a snapshot; native PHP can
share that array's property table with the object and change it during a walk.
`new ArrayObject($proxy)` is refused by
`ArrayObject` itself, which accepts only objects with the standard property
handlers. `is_countable()` is true only for proxies of Countable types.
`yield from $proxy` and `[...$proxy]` fail for proxies of non-Traversable
types like PHP does; `ReflectionClass::isIterable()` nevertheless reports the
generated class as iterable because it carries the iterator used for `foreach`.

Anonymous classes are supported like any other class: wrap an instance, or
mock one through its `get_class()` name. The generated proxy class name drops
the file/line part (`Greeter@anonymousProxy_1`); `$proxy::class` returns
the full anonymous class name.

Not intercepted, by design: static methods, static properties and class
constants (`$proxy::create()` etc. resolve normally on the generated class),
and `$this->...` calls made by original methods, which run on the target.

### Errors

| Situation                                                      | Exception                                  |
|----------------------------------------------------------------|--------------------------------------------|
| mock: chain ends at an unconfigured method                     | `Proxy\Exception\UnconfiguredMethod`       |
| mock: chain ends at an unconfigured property operation         | `Proxy\Exception\UnconfiguredProperty`     |
| unknown class, proxying a proxy, introspecting a non-proxy     | `Proxy\Exception\InvalidProxyTarget`       |
| enum, trait, internal final class, non-implementable interface | `Proxy\Exception\UnsupportedProxyType`     |
| interceptor cannot be invoked during dispatch                  | `Proxy\Exception\InvalidInterceptor`       |
| clone, serialize, unserialize, instantiation of a proxy class  | `Proxy\Exception\UnsupportedOperation`     |
| turning a proxy into a lazy object through reflection          | `Proxy\Exception\UnsupportedOperation`     |

All extend `Proxy\Exception\ProxyException` (an `\Exception`). Non-callable
factory arguments are rejected by PHP with `TypeError`. Contract
violations (wrong argument or return types, too few arguments, visibility)
raise PHP's own `TypeError`, `ArgumentCountError` and `Error` with the proxied
method's name. Diagnostics that the target raises while `proceed()` delegates
to it (an undefined-property warning, a hook's notice) are attributed to the
interceptor's `proceed()` line, since that is the frame executing at the time.
`unserialize()` of an `O:` payload naming a generated class throws when PHP
instantiates the class; a `C:` payload first gets PHP's "has no unserializer"
warning.

Mock chain exhaustion always throws `UnconfiguredMethod` or
`UnconfiguredProperty`, including when middleware forwards with `proceed()`.
`Proxy\Exception\NoOriginalImplementation` remains defined for a future explicit
original-call operation; it is not thrown by mock chain exhaustion.

## How it works

For every proxied type a final class such as `HttpClientProxy_1` is generated
at runtime and registered in the class table. It mirrors the type's metadata:
parent, interfaces, constants, static members, property declarations and
property layout. Its instance methods are internal trampolines that carry the
original signature (argument info, by-reference flags, return type, visibility,
`final`) and run the interceptor chain; static methods are the original
functions. Instances use a dedicated object handler table that routes property
and dimension access, casts, counting, iteration and method lookup through the
chain or to the wrapped target.

The class entry is assembled directly instead of through `zend_do_inheritance`
because the engine refuses to derive from `final` classes at link time and the
proxied class entry must not be modified (it may live in read-only opcache
memory). No `final` flag is ever cleared and no member is overridden in the
PHP sense.

For object `::class` expressions, a compiler AST hook adds an internal helper
call around PHP's native class-name operation. The helper translates generated
proxy names to their proxied types. It leaves operand evaluation and native
errors to PHP and does not replace execution or opcode handlers, so JIT remains
available. This adds a helper call to dynamic `::class` expressions, including
those used with ordinary objects. OPcache file-cache identities include the
transformation version, preventing reuse of this bytecode without the extension.

Proxies of classes with internal ancestry (`ArrayObject`, `DateTimeImmutable`,
`Exception`, ...) are allocated by the internal class so that internal
functions receiving the proxy see a valid, uninitialized object (they report
the usual "not correctly initialized" error instead of crashing); calls made
through the proxy are still delegated to the fully initialized target.

### Reading the implementation

| File | Responsibility |
| --- | --- |
| `proxy.c` | Public factories, callable normalization, and module/request lifetime |
| `proxy_class.c` | Generated class metadata and method trampolines |
| `proxy_dispatch.c` | Invocation objects, contract checks, interceptors, and target operations |
| `proxy_object.c` | Zend object handlers, property access, raw views, and garbage collection |
| `proxy_iterator.c` | Iterator delegation and property enumeration |
| `proxy_class_name.c` | Compile-time handling of object `::class` expressions |
| `php_proxy.h` | Shared structures and ownership contracts |
| `proxy.stub.php` | Public API and static-analysis types; source for `proxy_arginfo.h` |

Start with the factories in `proxy.c`, then follow a method into
`proxy_invoke()` or a property into `proxy_dispatch_property_ex()`. Both create
an invocation, call its interceptor, and validate the result. `proceed()` calls
the original target operation directly.

Class metadata lives until the end of the request. A proxy owns its target and
callbacks; an invocation owns a reference to its proxy and its captured values.
Releasing a value can run a PHP destructor that calls back into the extension,
so state restoration must happen before releasing old values. Property reads
also distinguish borrowed target storage from owned temporary results. These
ownership rules explain the cleanup order and guards around writable references.

## Design decisions worth knowing

The rules below describe the implementation's behavior. Accepted specification
adjustments and verification are tracked in [the review checklist](REVIEW_STATUS.md).

* **Proxy classes cannot be instantiated directly.** `new HttpClientProxy_1`,
  `ReflectionClass::newInstance()`, `newInstanceWithoutConstructor()`,
  `unserialize()` and `new static` inside a static factory invoked as
  `$proxy::create()` throw `UnsupportedOperation`, because an instance without
  interceptor configuration would have no behaviour. `newLazyGhost()` and
  `newLazyProxy()` of a generated class allocate the object inside the engine,
  past the class' allocator: such an object is recognized on first contact and
  becomes a plain object of the generated class with PHP's standard handlers.
  Such an object is an ordinary object of the generated class from birth, with
  PHP's standard handlers: its properties are its own (with the lazy
  initializer PHP attached), its methods throw `UnsupportedOperation`, and
  `Proxy::isProxy()` is false for it. `ReflectionClass::resetAsLazyGhost()`/
  `resetAsLazyProxy()` on a real proxy throw `UnsupportedOperation` and leave
  the proxy unchanged. With `ReflectionClass::SKIP_DESTRUCTOR` the reset cannot
  be refused up front; the proxy then refuses its next use, dropping a lazy
  ghost's state with the exception. A proxy made a lazy *proxy* that way and
  initialized stays unusable until released, and debug builds of PHP assert in
  `get_mangled_object_vars()`/`ReflectionObject::__toString()` for it.
* **Internal final classes are explicitly excluded** (`Closure`, `Generator`,
  `Fiber`, `WeakMap`, ...), following the supported-type boundary agreed for
  engine-managed internal objects. They raise `UnsupportedProxyType` from both
  `mock()` and `wrap()`; user-defined final classes remain supported.
* **Interfaces that PHP forbids user classes to implement are rejected**
  (`Throwable`, `DateTimeInterface`, `UnitEnum`, `BackedEnum`): internal code
  would reinterpret the proxy's memory as an internal object.
* **`static` return types resolve against the proxied type**, so a wrapped
  method returning `$this` (the target) satisfies `: static`. Return-type
  coercion uses the strict_types mode of the file declaring the proxied method;
  argument coercion uses the caller's mode, as in a direct call.
* **Typed property contracts are enforced on interceptor output.** A `GET`
  interceptor returning a string for an `int` property raises a `TypeError`;
  the engine (and its JIT) rely on typed slots never yielding other types.
* **Compound property operations stay transparent.** `$proxy->list[] = $x`,
  `$proxy->n++`, `foreach ($proxy->arr as &$v)`, `$ref = &$proxy->n` and
  `$proxy->n = &$v` work on ordinary target storage when interceptors forward
  the target's reference using `function &` or `fn &`; typed properties keep
  their type checks. An explicitly returned reference to other storage selects
  that storage, even if the target holds an equal value. A replacement value
  supplied where the VM needs storage, including an equal value returned by
  a value-returning callback, follows the engine's overloaded-property
  semantics (one `Indirect modification of overloaded property` notice, like
  `__get()`).
* **`$proxy::class` returns the original proxied type.** An interface mock
  returns its interface name.
  `get_class($proxy)` and `ReflectionObject($proxy)->getName()` still report the
  generated runtime name; `getParentClass()` / `getInterfaceNames()` report
  the proxied type. Reflection exposes internal method trampolines and inherited
  property declarations. Literal `SomeClass::class`, `self::class`,
  `parent::class` and `static::class` keep their normal PHP behavior. In
  particular, `static::class` in an inherited static method can still return
  the generated name. Dynamic constant lookup (`$proxy::{'class'}` or
  `$proxy::{$name}`) also remains native, including its errors. If it resolves
  the special constant name `class`, it returns the generated name; it is
  distinct from the object `::class` keyword operation.
  Object-name translation describes the generated class,
  so it also applies to unconfigured lazy objects allocated by reflection,
  without initializing them.
* **A proxy never compares equal to anything but itself** (`==` is symmetric
  and follows PHP's rule for objects of different classes). Comparing the
  target directly is always possible through `Proxy::target()`.
* **Private methods are interceptable** whenever PHP would dispatch the call
  through the object, including private methods of ancestors shadowed by
  redeclarations; `$this->private()` inside an original method runs on the
  target and is not intercepted (originals execute against the target).
  Zend's standard method resolver enforces scope and visibility. Every resolved
  instance method uses the same function-to-trampoline cache; there is no
  separate private-member lookup or interception path. Callables that the
  engine resolves without consulting the object's handlers are the exception
  described under "Method interception".
* **Proxies stay fully usable during request shutdown.** Resource destructors
  such as a stream wrapper's `stream_close()` run after the extension's own
  shutdown hook; they may call existing proxies and create new ones. The
  extension releases its request memory only after the executor shut down.
* **Weak references to a proxy are cleared before its target is released**, so
  a destructor that runs while the proxy is being freed cannot obtain the
  proxy from a `WeakReference` or `WeakMap`; a plain object's destructor can
  still resurrect the object that way.
* **Debug views of mocks show only uninitialized typed slots.** A mock has no
  storage; `var_dump()` lists its typed declared properties as
  `uninitialized(type)` and omits untyped ones.
* **The result of `++$proxy->x` through an interceptor is the arithmetic
  result** before property-type coercion (the stored value is coerced), as for
  any object with overloaded property access; `$proxy->x++` and the stored
  value are unaffected. Creating a dynamic property with `$proxy->new++` or
  `.=` through an interceptor reports "Undefined property" before the
  dynamic-property deprecation (the read runs before the write, as with
  `__get()`/`__set()` forwarding); without an interceptor PHP's order applies.
* **Deprecation notices** of `#[\Deprecated]` methods are emitted by the
  original call on wraps and therefore not at all on mocks.
* **Original-class reflection calls are excluded to preserve JIT.** A
  `ReflectionMethod` resolved from the original class (or an ancestor) invokes
  its original body on the proxy through `invoke()`, `invokeArgs()` or the
  closure returned by `getClosure($proxy)`. This also applies to strict mocks;
  the call skips method interception and does not delegate to a wrapped target.
  Operations performed inside that body on `$this` can still enter the proxy's
  method and property handlers.
  Reflect the method from the generated proxy class to use interception. The
  extension leaves Zend's execution hooks unchanged so JIT remains available.
* **Reference forwarding follows PHP callback syntax.** Both invocation types
  return references from `proceed()`. Callbacks that forward method or native
  hook reference returns must use `function &` or `fn &` to retain the alias.
  Returning by value is valid and has PHP's ordinary reference-loss semantics.
* **Typed property references preserve their type constraints**, including
  dynamic property names and call sites shared with ordinary objects. Taking a
  reference to an uninitialized nullable property initializes the target's
  storage to null, as ordinary PHP access does.

## Testing

```sh
make test
```

The suite covers final classes and methods, interface and abstract mocks,
references and named arguments, property interception including readonly,
asymmetric visibility and hooks, property enumeration and the internal
consumers of the properties table, engine-resolved callables, magic methods
and engine interfaces, type contracts, internal classes, lifetime, weak
references, lazy objects, shutdown and garbage collection. It runs on PHP 8.4
and 8.5, with and without the tracing JIT, in debug builds (which verify engine
invariants and report leaks) and release builds.

See [the review checklist](REVIEW_STATUS.md) for the verified build matrix and
the agreed specification adjustments.
