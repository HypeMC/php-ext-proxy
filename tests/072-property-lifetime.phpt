--TEST--
Property handlers retain the proxy and safely return storage when callbacks release it
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;

class Bag
{
    public array $items = [];
}

echo "released proxy and target\n";
$p = Proxy::wrap(new Bag, propertyInterceptor: function &($call) use (&$p) {
    $p = null;
    return $call->proceed();
});
$proxy = WeakReference::create($p);
$target = WeakReference::create(Proxy::target($p));
$p->items[] = 1;
var_dump($proxy->get(), $target->get());

echo "target remains independently owned\n";
$t = new Bag;
$p = Proxy::wrap($t, propertyInterceptor: function &($call) use (&$p) {
    $p = null;
    return $call->proceed();
});
$proxy = WeakReference::create($p);
$p->items[] = 2;
var_dump($proxy->get(), $t->items);

echo "returned reference keeps the property type\n";
$p = Proxy::wrap($t, propertyInterceptor: function &($call) use (&$p) {
    $p = null;
    return $call->proceed();
});
$items = & $p->items;
$items[] = 3;
try {
    $items = 'invalid';
} catch (TypeError $e) {
    echo "TypeError\n";
}
var_dump($t->items);
unset($items, $t);

echo "exception after release\n";
$p = Proxy::wrap(new Bag, propertyInterceptor: function &($call) use (&$p) {
    $p = null;
    throw new RuntimeException('stopped');
});
try {
    $p->items[] = 4;
} catch (RuntimeException $e) {
    echo $e->getMessage(), "\n";
}

echo "lazy target read without an interceptor\n";
$p = Proxy::wrap((new ReflectionClass(Bag::class))->newLazyGhost(function ($object) use (&$p) {
    $p = null;
    $object->items = [5];
}));
$proxy = WeakReference::create($p);
$target = WeakReference::create(Proxy::target($p));
$items = $p->items;
var_dump($items, $proxy->get(), $target->get());

echo "lazy writable fetch rejects a released object\n";
foreach ([false, true] as $wrapped) {
    $p = (new ReflectionClass(Bag::class))->newLazyGhost(function ($object) use (&$p) {
        $p = null;
        $object->items = [];
    });
    if ($wrapped) {
        $p = Proxy::wrap($p);
    }
    try {
        $p->items[] = 6;
    } catch (Error $e) {
        // The proxy reports its own release; PHP reports the released lazy object.
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}

echo "property coercion releases the proxy\n";
class Label
{
    public string $text = '';
}
class ReleaseProxyString
{
    public function __toString(): string
    {
        global $p;
        $p = null;
        return 'assigned';
    }
}
$t = new Label;
$p = Proxy::wrap($t, propertyInterceptor: fn ($call) => $call->proceed($call->value()));
$proxy = WeakReference::create($p);
$p->text = new ReleaseProxyString;
var_dump($t->text, $proxy->get());
?>
--EXPECT--
released proxy and target
NULL
NULL
target remains independently owned
NULL
array(1) {
  [0]=>
  int(2)
}
returned reference keeps the property type
TypeError
array(2) {
  [0]=>
  int(2)
  [1]=>
  int(3)
}
exception after release
stopped
lazy target read without an interceptor
array(1) {
  [0]=>
  int(5)
}
NULL
NULL
lazy writable fetch rejects a released object
Error: Lazy object was released during initialization
Error: Proxy object was released while accessing property Bag::$items
property coercion releases the proxy
string(8) "assigned"
NULL
