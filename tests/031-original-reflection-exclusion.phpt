--TEST--
Generated-class reflection is intercepted; original-class reflection is explicitly outside proxy dispatch
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Invocation;
use Proxy\Proxy;

final class ReflectedTarget
{
    final public function identify(string $label = 'default'): array
    {
        return [$label, $this];
    }
}

function reflectedCall(ReflectionMethod $method, object $object, string $form): array
{
    return match ($form) {
        'invoke' => $method->invoke($object, $form),
        'invokeArgs' => $method->invokeArgs($object, ['label' => $form]),
        'getClosure' => $method->getClosure($object)($form),
    };
}

$original = new ReflectionMethod(ReflectedTarget::class, 'identify');
$forms = ['invoke', 'invokeArgs', 'getClosure'];

echo "--- configured mock ---\n";
$calls = 0;
$mock = Proxy::mock(ReflectedTarget::class, methodInterceptor: function (Invocation $call) use (&$calls): array {
    ++$calls;
    return ['handled:' . $call->args()[0], $call->proxy()];
});
$generated = new ReflectionMethod($mock, 'identify');
foreach ($forms as $form) {
    [$label, $object] = reflectedCall($generated, $mock, $form);
    echo $label, ':', $object === $mock ? 'mock' : 'other', "\n";
    [$label, $object] = reflectedCall($original, $mock, $form);
    echo $label, ':', $object === $mock ? 'mock' : 'other', "\n";
}
echo "interceptions:$calls\n";

echo "--- strict mock ---\n";
$strict = Proxy::mock(ReflectedTarget::class);
$generated = new ReflectionMethod($strict, 'identify');
foreach ($forms as $form) {
    try {
        reflectedCall($generated, $strict, $form);
    } catch (Throwable $error) {
        echo $form, ':', $error::class, "\n";
    }
    [$label, $object] = reflectedCall($original, $strict, $form);
    echo $label, ':', $object === $strict ? 'mock' : 'other', "\n";
}

echo "--- wrapped target ---\n";
$calls = 0;
$target = new ReflectedTarget();
$wrapped = Proxy::wrap($target, methodInterceptor: function (Invocation $call) use (&$calls): array {
    ++$calls;
    return $call->proceed(...$call->args());
});
$generated = new ReflectionMethod($wrapped, 'identify');
foreach ($forms as $form) {
    [$label, $object] = reflectedCall($generated, $wrapped, $form);
    echo $label, ':', $object === $target ? 'target' : 'other', "\n";
    [$label, $object] = reflectedCall($original, $wrapped, $form);
    echo $label, ':', $object === $wrapped ? 'proxy' : 'other', "\n";
}
echo "interceptions:$calls\n";

echo "--- ancestor reflection re-enters the proxy through \$this ---\n";
class ReflectedBase
{
    public int $value = 10;

    public function read(): array
    {
        return [$this->value, $this];
    }
}
class ReflectedChild extends ReflectedBase
{
}
$ancestor = new ReflectionMethod(ReflectedBase::class, 'read');
$reads = 0;
$child = Proxy::wrap(new ReflectedChild(), propertyInterceptor: function ($access) use (&$reads): mixed {
    ++$reads;
    return $access->proceed();
});
[$value, $object] = $ancestor->invoke($child);
echo $value, ':', $object === $child ? 'proxy' : 'other', ":reads=$reads\n";
try {
    $ancestor->invoke(Proxy::mock(ReflectedChild::class));
} catch (Throwable $error) {
    echo $error::class, ': ', $error->getMessage(), "\n";
}
?>
--EXPECT--
--- configured mock ---
handled:invoke:mock
invoke:mock
handled:invokeArgs:mock
invokeArgs:mock
handled:getClosure:mock
getClosure:mock
interceptions:3
--- strict mock ---
invoke:Proxy\Exception\UnconfiguredMethod
invoke:mock
invokeArgs:Proxy\Exception\UnconfiguredMethod
invokeArgs:mock
getClosure:Proxy\Exception\UnconfiguredMethod
getClosure:mock
--- wrapped target ---
invoke:target
invoke:proxy
invokeArgs:target
invokeArgs:proxy
getClosure:target
getClosure:proxy
interceptions:3
--- ancestor reflection re-enters the proxy through $this ---
10:proxy:reads=1
Proxy\Exception\UnconfiguredProperty: Unconfigured GET of property ReflectedChild::$value on mock
