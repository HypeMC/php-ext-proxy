--TEST--
Lifetime: weak references during destruction, iterators and enumeration that release the proxy, shutdown-time use
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;

echo "--- WeakReference and WeakMap are cleared before the target's destructor runs ---\n";
class Bag
{
    public function __destruct()
    {
        $got = $GLOBALS['weak']->get();
        echo 'destructor sees: ', $got === null ? 'null' : get_class($got), "\n";
        $GLOBALS['kept'] = $got;
        echo 'weak map count: ', count($GLOBALS['map']), "\n";
    }
}
$p = Proxy::wrap(new Bag);
$weak = WeakReference::create($p);
$map = new WeakMap;
$map[$p] = 'entry';
unset($p);
var_dump($kept, $weak->get(), count($map));

echo "--- interceptor bound to an object whose destructor resolves the weak reference ---\n";
class Holder
{
    public function __destruct()
    {
        echo 'holder destructor sees: ', var_export($GLOBALS['weak']->get(), true), "\n";
    }

    public function intercept($call)
    {
        return $call->proceed(...$call->args());
    }
}
$p = Proxy::wrap(new stdClass, methodInterceptor: [new Holder, 'intercept']);
$weak = WeakReference::create($p);
unset($p);

echo "--- getIterator() interceptor releasing the last proxy reference ---\n";
class Agg implements IteratorAggregate
{
    public function getIterator(): Iterator
    {
        return new ArrayIterator([1, 2]);
    }
}
$p = Proxy::wrap(new Agg, methodInterceptor: function ($c) {
    unset($GLOBALS['p']);
    return null;
});
try {
    foreach ($p as $v) {
    }
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$p = Proxy::wrap(new Agg, methodInterceptor: function ($c) {
    unset($GLOBALS['p']);
    return $c->proceed();
});
foreach ($p as $v) {
    echo $v, ';';
}
echo "\n";

echo "--- lazy target initialized by foreach releases the proxy ---\n";
class Items
{
    public array $items = [];

    public function n(): int
    {
        return 1;
    }
}
$p = Proxy::wrap((new ReflectionClass(Items::class))->newLazyGhost(function ($o) {
    unset($GLOBALS['p']);
    $o->items = [1];
}));
foreach ($p as $k => $v) {
    echo "$k=", json_encode($v), "\n";
}
$p = Proxy::wrap((new ReflectionClass(Items::class))->newLazyGhost(function ($o) {
    unset($GLOBALS['p']);
    $o->items = [2];
}));
var_dump(get_object_vars($p));

echo "--- objects the engine allocates for a generated class are plain objects ---\n";
$p = Proxy::wrap(new Items);
$ghost = (new ReflectionClass($p))->newLazyGhost(function ($o) {
    echo "[init]";
    $o->items = [7];
});
var_dump(Proxy::isProxy($ghost));
try {
    $ghost->n();
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
var_dump($ghost->items, (array) $ghost, json_encode($ghost));
foreach ($ghost as $k => $v) {
    echo "$k=", json_encode($v), "\n";
}
$cycle = (new ReflectionClass($p))->newLazyGhost(function ($o) use (&$cycle) {
});
$weak = WeakReference::create($cycle);
unset($cycle);
var_dump(gc_collect_cycles() > 0, $weak->get());
$walked = (new ReflectionClass($p))->newLazyGhost(fn ($o) => null);
array_walk($walked, fn () => 1);
var_dump(gc_collect_cycles());
unset($ghost, $walked);

echo "--- a proxy cannot be turned into a lazy object ---\n";
$p = Proxy::wrap(new Items);
$rc = new ReflectionClass($p);
foreach (['resetAsLazyGhost' => fn ($o) => null, 'resetAsLazyProxy' => fn ($o) => new Items] as $method => $initializer) {
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        try {
            $rc->$method($p, $initializer);
            echo "$method: not refused\n";
        } catch (\Proxy\Exception\UnsupportedOperation $e) {
            echo "$method #$attempt: ", preg_replace('/Proxy_\w+/', 'Proxy', $e->getMessage()), "\n";
        }
    }
}
var_dump($rc->isUninitializedLazyObject($p), $p->n(), count(get_mangled_object_vars($p)));
// SKIP_DESTRUCTOR bypasses the handler that refuses the reset: the proxy is
// then refused on its next use, which also drops the lazy state
$rc->resetAsLazyGhost($p, function ($o) use (&$p) {
    echo "never\n";
}, ReflectionClass::SKIP_DESTRUCTOR);
try {
    $p->n();
} catch (Throwable $e) {
    echo get_class($e), ': ', preg_replace('/Proxy_\w+/', 'Proxy', $e->getMessage()), "\n";
}
var_dump($rc->isUninitializedLazyObject($p), $p->n());
$weak = WeakReference::create($p);
unset($p);
var_dump($weak->get());
foreach ([fn ($p) => count($p), fn ($p) => serialize($p)] as $use) {
    $p = Proxy::wrap(new Items);
    $rc->resetAsLazyGhost($p, fn ($o) => null, ReflectionClass::SKIP_DESTRUCTOR);
    try {
        $use($p);
    } catch (Throwable $e) {
        echo get_class($e), ': ', preg_replace('/Proxy_\w+/', 'Proxy', $e->getMessage()), "\n";
    }
}

echo "--- proxies stay usable after RSHUTDOWN and new ones can still be created ---\n";
class Base
{
    private function secret(): string
    {
        return 'secret';
    }

    public static function callOn(object $o, string $m): string
    {
        return $o->$m();
    }
}
class Child extends Base
{
    public function secret(): string
    {
        return 'child';
    }
}
class Fresh
{
    public function hi(): string
    {
        return 'hi';
    }
}
class LateWrapper
{
    public $context;
    public static ?object $proxy = null;

    public function stream_open($path, $mode, $options, &$opened)
    {
        return true;
    }

    public function stream_close()
    {
        // runs while resources are closed, after the extension's RSHUTDOWN and
        // after output has been shut down: failures surface as leak reports or
        // crashes of the debug build, successes are silent
        Base::callOn(self::$proxy, 'secret');
        Proxy::wrap(new Child)->secret();
        Proxy::wrap(new Fresh)->hi();
        Proxy::mock(Fresh::class, methodInterceptor: fn () => 'mock')->hi();
        foreach (Proxy::wrap(new Items) as $v) {
        }
        json_encode(Proxy::wrap(new Items));
    }
}
stream_wrapper_register('late', LateWrapper::class);
LateWrapper::$proxy = Proxy::wrap(new Child);
$open = fopen('late://x', 'r');
echo "done\n";
?>
--EXPECTF--
--- WeakReference and WeakMap are cleared before the target's destructor runs ---
destructor sees: null
weak map count: 0
NULL
NULL
int(0)
--- interceptor bound to an object whose destructor resolves the weak reference ---
holder destructor sees: NULL
--- getIterator() interceptor releasing the last proxy reference ---
TypeError: Agg::getIterator(): Return value must be of type Iterator, null returned
1;2;
--- lazy target initialized by foreach releases the proxy ---
items=[1]
array(1) {
  ["items"]=>
  array(1) {
    [0]=>
    int(2)
  }
}
--- objects the engine allocates for a generated class are plain objects ---
bool(false)
Proxy\Exception\UnsupportedOperation: Proxy object of class ItemsProxy_%s was not created through Proxy\Proxy::mock() or Proxy\Proxy::wrap()
[init]array(1) {
  [0]=>
  int(7)
}
array(1) {
  ["items"]=>
  array(1) {
    [0]=>
    int(7)
  }
}
string(13) "{"items":[7]}"
items=[7]
bool(true)
NULL
int(0)
--- a proxy cannot be turned into a lazy object ---
resetAsLazyGhost #1: Proxy object of class ItemsProxy cannot be made lazy
resetAsLazyGhost #2: Proxy object of class ItemsProxy cannot be made lazy
resetAsLazyProxy #1: Proxy object of class ItemsProxy cannot be made lazy
resetAsLazyProxy #2: Proxy object of class ItemsProxy cannot be made lazy
bool(false)
int(1)
int(1)
Proxy\Exception\UnsupportedOperation: Proxy object of class ItemsProxy cannot be used as a lazy object
bool(false)
int(1)
NULL
TypeError: count(): Argument #1 ($value) must be of type Countable|array, ItemsProxy given
Proxy\Exception\UnsupportedOperation: Proxy object of class ItemsProxy cannot be used as a lazy object
--- proxies stay usable after RSHUTDOWN and new ones can still be created ---
done
