--TEST--
__call, __toString, __invoke, __get/__set, Countable, ArrayAccess, Iterator, IteratorAggregate, JsonSerializable
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;
use Proxy\Invocation;
use Proxy\PropertyInvocation as PI;
use Proxy\PropertyOperation as Op;

class Magic implements Countable, ArrayAccess, IteratorAggregate, JsonSerializable, Stringable
{
    private array $store = ['a' => 1, 'b' => 2];
    private array $dyn = [];

    public function __call(string $name, array $args): string
    {
        return "__call:$name(" . implode(',', $args) . ")";
    }

    public static function __callStatic(string $name, array $args): string
    {
        return "__callStatic:$name";
    }

    public function __get(string $n): mixed
    {
        echo "[__get $n]";
        return $this->dyn[$n] ?? null;
    }

    public function __set(string $n, mixed $v): void
    {
        echo "[__set $n]";
        $this->dyn[$n] = $v;
    }

    public function __isset(string $n): bool
    {
        return isset($this->dyn[$n]);
    }

    public function __unset(string $n): void
    {
        unset($this->dyn[$n]);
    }

    public function __toString(): string
    {
        return 'magic!';
    }

    public function __invoke(int $x): int
    {
        return $x * 2;
    }

    public function count(): int
    {
        return count($this->store);
    }

    public function offsetExists(mixed $o): bool
    {
        return isset($this->store[$o]);
    }

    public function offsetGet(mixed $o): mixed
    {
        return $this->store[$o] ?? null;
    }

    public function offsetSet(mixed $o, mixed $v): void
    {
        if ($o === null) {
            $this->store[] = $v;
        } else {
            $this->store[$o] = $v;
        }
    }

    public function offsetUnset(mixed $o): void
    {
        unset($this->store[$o]);
    }

    public function getIterator(): Iterator
    {
        return new ArrayIterator($this->store);
    }

    public function jsonSerialize(): array
    {
        return $this->store;
    }

    private function secret(): string
    {
        return 'secret';
    }

    public function callSecret(object $o): string
    {
        return $o->secret();
    }
}
class It implements Iterator
{
    private int $i = 0;

    public function __construct(private array $items)
    {
    }

    public function current(): mixed
    {
        return $this->items[$this->i];
    }

    public function key(): mixed
    {
        return $this->i;
    }

    public function next(): void
    {
        $this->i++;
    }

    public function rewind(): void
    {
        $this->i = 0;
    }

    public function valid(): bool
    {
        return $this->i < count($this->items);
    }
}

$calls = [];
$target = new Magic();
$proxy = Proxy::wrap($target, methodInterceptor: function (Invocation $call) use (&$calls) {
    $calls[] = $call->method();
    return $call->proceed(...$call->args());
});

echo "--- __call ---\n";
var_dump($proxy->undefinedThing(1, 2));
var_dump(
    method_exists($proxy, 'undefinedThing'),
    is_callable([$proxy, 'undefinedThing']),
    call_user_func([$proxy, 'viaCuf'], 'x')
);
var_dump($proxy::staticMagic());
$dynamicProxy = Proxy::wrap(
    $target,
    methodInterceptor: fn (Invocation $call) => $call->method() === 'virt'
        ? 'intercepted virt:' . $call->method() . ':' . $call->hasOriginal()
        : $call->proceed(...$call->args())
);
var_dump($dynamicProxy->virt(1));
var_dump($dynamicProxy->other(1));
$mock = Proxy::mock(
    Magic::class,
    methodInterceptor: fn (Invocation $call) => $call->method() === 'dyn'
        ? 'mock-dyn'
        : $call->proceed(...$call->args())
);
var_dump($mock->dyn());
try {
    $mock->other();
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
echo "--- inaccessible method with __call ---\n";
var_dump($proxy->secret());
var_dump($target->callSecret($proxy));
echo "--- __toString / Stringable ---\n";
var_dump((string) $proxy, "x$proxy", $proxy instanceof Stringable, strlen($proxy));
echo "--- __invoke ---\n";
var_dump($proxy(21), is_callable($proxy), array_map($proxy, [1, 2]));
$invokeClosure = Closure::fromCallable($proxy);
var_dump($invokeClosure(5));
echo "--- Countable / ArrayAccess ---\n";
var_dump(count($proxy), isset($proxy['a']), isset($proxy['zz']), $proxy['a'], empty($proxy['b']));
$proxy['c'] = 3;
$proxy[] = 4;
unset($proxy['a']);
var_dump(count($target), $proxy['c'], $target['c'] ?? null);
var_dump($proxy['missing'] ?? 'default');
echo "--- IteratorAggregate / Iterator ---\n";
foreach ($proxy as $k => $v) {
    echo "$k=$v ";
}
echo "\n";
var_dump(iterator_to_array($proxy));
$iterator = Proxy::wrap(
    new It(['x', 'y']),
    methodInterceptor: fn (Invocation $call) => $call->method() === 'current'
        ? strtoupper($call->proceed())
        : $call->proceed(...$call->args())
);
foreach ($iterator as $k => $v) {
    echo "$k=$v ";
}
echo "\n";
var_dump(iterator_to_array($iterator));
$iteratorMock = Proxy::mock(Iterator::class, methodInterceptor: function (Invocation $call) {
    static $n = 0;
    return match ($call->method()) {
        'rewind', 'next' => null,
        'valid' => $n++ < 2,
        'current' => 'c',
        'key' => 'k',
        default => $call->proceed(...$call->args()),
    };
});
foreach ($iteratorMock as $k => $v) {
    echo "$k=$v ";
}
echo "\n";
echo "--- json ---\n";
var_dump(json_encode($proxy));
var_dump(json_encode(Proxy::mock(JsonSerializable::class, methodInterceptor: fn () => ['mocked' => true])));
echo "--- magic props ---\n";
$proxy->color = 'red';
var_dump($proxy->color, isset($proxy->color), isset($proxy->none));
unset($proxy->color);
var_dump(isset($proxy->color));
$propertyProxy = Proxy::wrap(
    $target,
    propertyInterceptor: fn (PI $prop) => $prop->property() === 'color' && $prop->operation() === Op::GET
        ? 'blue'
        : $prop->proceed(...($prop->operation() === Op::SET
            ? [$prop->value()]
            : []))
);
var_dump($propertyProxy->color);
$propertyProxy->color = 'green';
var_dump($target->color, $propertyProxy->color);
echo "--- first-class callable / closures ---\n";
$countCallable = $proxy->count(...);
var_dump($countCallable(), (new ReflectionFunction($countCallable))->getName());
$offsetCallable = Closure::fromCallable([$proxy, 'offsetGet']);
var_dump($offsetCallable('b'));
var_dump((new ReflectionMethod($proxy, 'count'))->invoke($proxy));
var_dump((new ReflectionMethod($proxy, 'count'))->getClosure($proxy)());
echo "--- calls seen by method interceptor ---\n";
echo implode(',', array_unique($calls)), "\n";
echo "done\n";
--EXPECT--
--- __call ---
string(26) "__call:undefinedThing(1,2)"
bool(false)
bool(true)
string(16) "__call:viaCuf(x)"
string(24) "__callStatic:staticMagic"
string(23) "intercepted virt:virt:1"
string(15) "__call:other(1)"
string(8) "mock-dyn"
Proxy\Exception\UnconfiguredMethod: Call to unconfigured method Magic::other() on mock
--- inaccessible method with __call ---
string(15) "__call:secret()"
string(6) "secret"
--- __toString / Stringable ---
string(6) "magic!"
string(7) "xmagic!"
bool(true)
int(6)
--- __invoke ---
int(42)
bool(true)
array(2) {
  [0]=>
  int(2)
  [1]=>
  int(4)
}
int(10)
--- Countable / ArrayAccess ---
int(2)
bool(true)
bool(false)
int(1)
bool(false)
int(3)
int(3)
int(3)
string(7) "default"
--- IteratorAggregate / Iterator ---
b=2 c=3 0=4 
array(3) {
  ["b"]=>
  int(2)
  ["c"]=>
  int(3)
  [0]=>
  int(4)
}
0=X 1=Y 
array(2) {
  [0]=>
  string(1) "X"
  [1]=>
  string(1) "Y"
}
k=c k=c 
--- json ---
string(19) "{"b":2,"c":3,"0":4}"
string(15) "{"mocked":true}"
--- magic props ---
[__set color][__get color]string(3) "red"
bool(true)
bool(false)
bool(false)
string(4) "blue"
[__set color][__get color]string(5) "green"
string(4) "blue"
--- first-class callable / closures ---
int(3)
string(5) "count"
int(2)
int(3)
int(3)
--- calls seen by method interceptor ---
undefinedThing,viaCuf,secret,__toString,__invoke,count,offsetExists,offsetGet,offsetSet,offsetUnset,getIterator,jsonSerialize
done
