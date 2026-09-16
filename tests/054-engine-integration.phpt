--TEST--
Engine integration: is_countable(), instantiation paths, by-reference method results, callback contracts
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Invocation;
use Proxy\Proxy;

function attempt(string $label, callable $fn): void
{
    echo $label, ': ';
    try {
        $r = $fn();
        echo is_string($r) ? $r : json_encode($r), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}

echo "--- is_countable() follows the proxied type ---\n";
class Plain
{
}
var_dump(
    is_countable(Proxy::wrap(new Plain)),
    is_countable(Proxy::wrap(new ArrayObject([1]))),
    count(Proxy::wrap(new ArrayObject([1, 2])))
);
attempt('count() of a non-Countable proxy', fn () => count(Proxy::wrap(new Plain)));

echo "--- every instantiation path of a generated class is refused ---\n";
class Factory
{
    public static function make(): static
    {
        return new static;
    }
}
$class = get_class(Proxy::wrap(new Plain));
attempt('new', fn () => new $class);
attempt(
    'newInstanceWithoutConstructor',
    fn () => (new ReflectionClass($class))->newInstanceWithoutConstructor()
);
attempt('newInstance', fn () => (new ReflectionClass($class))->newInstance());
attempt('new static through the proxy', fn () => Proxy::wrap(new Factory)::make());
attempt('unserialize O:', fn () => unserialize('O:' . strlen($class) . ':"' . $class . '":0:{}'));
attempt('unserialize C:', fn () => unserialize('C:' . strlen($class) . ':"' . $class . '":0:{}'));
attempt('serialize', fn () => serialize(Proxy::wrap(new Plain)));

echo "--- by-reference methods with value-returning interceptors get PHP's diagnostics ---\n";
class Store implements ArrayAccess
{
    public array $d = [];

    public function &offsetGet($o): mixed
    {
        $this->d[$o] ??= null;
        return $this->d[$o];
    }

    public function offsetSet($o, $v): void
    {
        $this->d[$o] = $v;
    }

    public function offsetExists($o): bool
    {
        return isset($this->d[$o]);
    }

    public function offsetUnset($o): void
    {
        unset($this->d[$o]);
    }

    public function &all(): array
    {
        return $this->d;
    }
}
set_error_handler(function (int $no, string $msg) {
    echo "notice: $msg\n";
    return true;
});
$t = new Store;
$p = Proxy::wrap($t, methodInterceptor: fn (Invocation $c) => $c->proceed(...$c->args()));
$p['a']['x'] = 1;
$r = &$p->all();
$r['y'] = 2;
var_dump($t->d);
$t = new Store;
$p = Proxy::wrap($t, methodInterceptor: function &(Invocation $c) {
    return $c->proceed(...$c->args());
});
$p['a']['x'] = 1;
$r = &$p->all();
$r['y'] = 2;
var_dump($t->d);
restore_error_handler();

echo "--- method callbacks receive exactly one Invocation ---\n";
class Runner
{
    public function run(string $x): string
    {
        return "run:$x";
    }
}
$p = Proxy::wrap(new Runner, methodInterceptor: fn (Invocation $c, string $x) => "cb:$x");
echo "registration succeeded\n";
attempt('extra required parameter', fn () => $p->run('a'));
$p = Proxy::wrap(new Runner, methodInterceptor: fn (string $c) => "cb:$c");
attempt('wrong parameter type', fn () => $p->run('a'));
$p = Proxy::wrap(
    new Runner,
    methodInterceptor: fn (Invocation $c, string $x = 'default') => "cb:$x:" . $c->args()[0]
);
attempt('optional extra parameter', fn () => $p->run('a'));
echo "done\n";
?>
--EXPECTF--
--- is_countable() follows the proxied type ---
bool(false)
bool(true)
int(2)
count() of a non-Countable proxy: TypeError: count(): Argument #1 ($value) must be of type Countable|array, PlainProxy_%s given
--- every instantiation path of a generated class is refused ---
new: Proxy\Exception\UnsupportedOperation: Proxy class PlainProxy_%s cannot be instantiated directly; use Proxy\Proxy::mock() or Proxy\Proxy::wrap()
newInstanceWithoutConstructor: Proxy\Exception\UnsupportedOperation: Proxy class PlainProxy_%s cannot be instantiated directly; use Proxy\Proxy::mock() or Proxy\Proxy::wrap()
newInstance: Proxy\Exception\UnsupportedOperation: Proxy class PlainProxy_%s cannot be instantiated directly; use Proxy\Proxy::mock() or Proxy\Proxy::wrap()
new static through the proxy: Proxy\Exception\UnsupportedOperation: Proxy class FactoryProxy_%s cannot be instantiated directly; use Proxy\Proxy::mock() or Proxy\Proxy::wrap()
unserialize O:: Proxy\Exception\UnsupportedOperation: Proxy class PlainProxy_%s cannot be instantiated directly; use Proxy\Proxy::mock() or Proxy\Proxy::wrap()
unserialize C:: 
Warning: Class PlainProxy_%s has no unserializer in %s on line %d
Proxy\Exception\UnsupportedOperation: Proxy class PlainProxy_%s cannot be instantiated directly; use Proxy\Proxy::mock() or Proxy\Proxy::wrap()
serialize: Proxy\Exception\UnsupportedOperation: Proxy objects of class PlainProxy_%s cannot be serialized
--- by-reference methods with value-returning interceptors get PHP's diagnostics ---
notice: Indirect modification of overloaded element of StoreProxy_%s has no effect
notice: Only variables should be assigned by reference
array(1) {
  ["a"]=>
  NULL
}
array(2) {
  ["a"]=>
  array(1) {
    ["x"]=>
    int(1)
  }
  ["y"]=>
  int(2)
}
--- method callbacks receive exactly one Invocation ---
registration succeeded
extra required parameter: ArgumentCountError: Too few arguments to function {closure:%s:%d}(), 1 passed and exactly 2 expected
wrong parameter type: TypeError: {closure:%s:%d}(): Argument #1 ($c) must be of type string, Proxy\Invocation given
optional extra parameter: cb:default:a
done
