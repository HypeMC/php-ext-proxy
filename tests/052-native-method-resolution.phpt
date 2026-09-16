--TEST--
Native method resolution selects private ancestors, public redeclarations, and magic fallback before proxy dispatch
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Invocation;
use Proxy\Proxy;

class ScopedBase
{
    private function identify(int &$value): string
    {
        ++$value;
        return 'base:' . $value;
    }

    public static function invoke(self $object, int &$value): string
    {
        return $object->identify($value);
    }

    public static function callableFor(self $object): Closure
    {
        return $object->identify(...);
    }
}
class ScopedChild extends ScopedBase
{
    final public function identify(string $value): string
    {
        return 'child:' . $value;
    }
}

$calls = 0;
$target = new ScopedChild;
$proxy = Proxy::wrap($target, methodInterceptor: function (Invocation $call) use (&$calls): string {
    ++$calls;
    return 'handled:' . $call->proceed(...$call->args());
});
$value = 0;
echo $proxy->identify('public'), "\n";
echo ScopedBase::invoke($proxy, $value), "\n";
$closure = ScopedBase::callableFor($proxy);
echo $closure($value), "\n";
for ($i = 0; $i < 20; ++$i) {
    ScopedBase::invoke($proxy, $value);
}
echo "calls:$calls value:$value\n";

$mock = Proxy::mock(ScopedChild::class, methodInterceptor: function (Invocation $call): string {
    $args = $call->args();
    if (is_int($args[0])) {
        $args[0] += 10;
    }
    return 'mock:' . $args[0];
});
echo $mock->identify('public'), "\n";
echo ScopedBase::invoke($mock, $value), "\n";
echo "value:$value\n";

$strict = Proxy::mock(ScopedChild::class);
try {
    ScopedBase::invoke($strict, $value);
} catch (Throwable $error) {
    echo $error::class, "\n";
}

class MagicScope
{
    private function secret(): string
    {
        return 'private';
    }

    protected function hidden(): string
    {
        return 'protected';
    }

    public function __call(string $name, array $args): string
    {
        return 'magic:' . $name . ':' . implode(',', $args);
    }
}
$magic = Proxy::wrap(new MagicScope, methodInterceptor: function (Invocation $call): string {
    return 'handled:' . $call->proceed(...$call->args());
});
echo $magic->secret('one'), "\n";
echo $magic->hidden('two'), "\n";
echo $magic->missing('three'), "\n";
?>
--EXPECT--
handled:child:public
handled:base:1
handled:base:2
calls:23 value:22
mock:public
mock:32
value:32
Proxy\Exception\UnconfiguredMethod
handled:magic:secret:one
handled:magic:hidden:two
handled:magic:missing:three
