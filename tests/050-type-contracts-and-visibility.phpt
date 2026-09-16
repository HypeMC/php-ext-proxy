--TEST--
Return and argument type contracts, strict_types, method visibility, static methods
--EXTENSIONS--
proxy
--FILE--
<?php
declare(strict_types=1);
use Proxy\Proxy;
use Proxy\Invocation;

require __DIR__ . '/weak_typed.inc';

echo "--- return type violations by interceptors ---\n";
$target = new Typed();
$proxy = Proxy::wrap($target, methodInterceptor: fn (Invocation $call) => match ($call->method()) {
    'count' => 'five',
    'name' => 5,
    'nothing' => 'x',
    'never', 'maybe' => null,
    'union' => 'str',
    'self' => $call->proxy(),
    'untyped' => [1],
    'takesInt' => $call->args()[0] + 1,
    default => $call->proceed(...$call->args()),
});
foreach (['count', 'name', 'nothing', 'never', 'maybe', 'union', 'untyped'] as $method) {
    try {
        var_dump($proxy->$method());
    } catch (Throwable $error) {
        echo "$method: ", get_class($error), ": ", $error->getMessage(), "\n";
    }
}
var_dump($proxy->me() === $target, $proxy->self() === $proxy);
echo "--- weak-mode return coercion (declaring file is weak) ---\n";
$weakProxy = Proxy::wrap($target, methodInterceptor: fn () => 5);
var_dump($weakProxy->name());
echo "--- argument contract (strict caller) ---\n";
try {
    $proxy->takesInt('5');
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
var_dump($proxy->takesInt(5));
try {
    $proxy->takesObj(new stdClass);
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
var_dump($proxy->takesObj($proxy), $proxy->takesUnion(3), $proxy->takesUnion('s'));
try {
    $proxy->takesUnion(1.5);
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
echo "--- weak caller via include ---\n";
$weak = include __DIR__ . '/weak_inc.inc';
var_dump($weak($proxy));
echo "--- visibility ---\n";
try {
    $proxy->prot();
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
try {
    $proxy->priv();
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
var_dump($proxy->callPriv());
var_dump($target->callPrivOn($proxy));
$scopedProxy = Proxy::wrap($target, methodInterceptor: fn (Invocation $call) => match ($call->method()) {
    'priv' => 'intercepted priv',
    'prot' => 'intercepted prot',
    default => $call->proceed(...$call->args())
});
var_dump($target->callPrivOn($scopedProxy), $scopedProxy->callPriv());
$bound = Closure::bind(fn () => $this->prot(), $scopedProxy, Typed::class);
var_dump($bound());
echo "--- static via instance ---\n";
var_dump($proxy->stat(), $proxy::stat(), Typed::stat());
$staticCalls = 0;
$staticProxy = Proxy::wrap($target, methodInterceptor: function () use (&$staticCalls) {
    ++$staticCalls;
    return 'x';
});
var_dump($staticProxy::stat(), $staticCalls);
echo "--- explicit constructor call ---\n";
$constructorProxy = Proxy::wrap(
    $target,
    methodInterceptor: fn (Invocation $call) => $call->proceed(($call->args()[0] ?? 0) * 10)
);
$constructorProxy->__construct(4);
var_dump($target->ctorArg);
echo "--- deprecated + nodiscard style attributes ---\n";
class Dep
{
    #[\Deprecated('use other()')]
    public function old(): int
    {
        return 1;
    }
}
$d = Proxy::wrap(new Dep());
var_dump($d->old());
echo "done\n";
--EXPECTF--
--- return type violations by interceptors ---
count: TypeError: Typed::count(): Return value must be of type int, string returned
string(1) "5"
nothing: TypeError: Typed::nothing(): Return value must be of type void, string returned
never: TypeError: Typed::never(): never-returning method must not implicitly return
NULL
string(3) "str"
array(1) {
  [0]=>
  int(1)
}
bool(true)
bool(true)
--- weak-mode return coercion (declaring file is weak) ---
string(1) "5"
--- argument contract (strict caller) ---
TypeError: Typed::takesInt(): Argument #%d ($i) must be of type int, string given, called in %s050-type-contracts-and-visibility.php on line %d
int(6)
TypeError: Typed::takesObj(): Argument #%d ($t) must be of type Typed, stdClass given, called in %s050-type-contracts-and-visibility.php on line %d
string(12) "TypedProxy_1"
string(7) "integer"
string(6) "string"
TypeError: Typed::takesUnion(): Argument #%d ($v) must be of type string|int, float given, called in %s050-type-contracts-and-visibility.php on line %d
--- weak caller via include ---
int(8)
--- visibility ---
Error: Call to protected method Typed::prot() from global scope
Error: Call to private method Typed::priv() from global scope
string(4) "priv"
string(4) "priv"
string(16) "intercepted priv"
string(4) "priv"
string(16) "intercepted prot"
--- static via instance ---
string(4) "stat"
string(4) "stat"
string(4) "stat"
string(4) "stat"
int(0)
--- explicit constructor call ---
int(40)
--- deprecated + nodiscard style attributes ---

Deprecated: Method Dep::old() is deprecated, use other() in %s050-type-contracts-and-visibility.php on line %d
int(1)
done
