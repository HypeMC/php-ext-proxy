--TEST--
Magic calls preserve native argument and return reference behavior through the interceptor
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Invocation;
use Proxy\Proxy;

class MagicArguments
{
    public function __call(string $name, array $args): array
    {
        $key = array_key_first($args);
        $reference = ReflectionReference::fromArrayElement($args, $key) !== null;
        $args[$key] = 99;
        return [$name, $key, $reference];
    }
}
function argumentCall(object $object, string $form): array
{
    $value = 1;
    $alias = &$value;
    $result = match ($form) {
        'direct' => $object->missing($alias),
        'unpack' => $object->missing(...[&$value]),
        'array' => call_user_func_array([$object, 'missing'], [&$value]),
        'named array' => call_user_func_array([$object, 'missing'], ['item' => &$value]),
        'first class' => ($object->missing(...))($alias),
        'closure' => Closure::fromCallable([$object, 'missing'])($alias),
    };
    return [$result, $value];
}
foreach (['direct', 'unpack', 'array', 'named array', 'first class', 'closure'] as $form) {
    $native = argumentCall(new MagicArguments, $form);
    $wrapped = argumentCall(Proxy::wrap(new MagicArguments), $form);
    $intercepted = argumentCall(Proxy::wrap(
        new MagicArguments,
        methodInterceptor: fn (Invocation $call) => $call->proceed(...$call->args()),
    ), $form);
    // proceed() accepts references, so forwarding can add a reference to a
    // temporary argument. The original caller's aliasing must still agree.
    $interceptionMatches = $native[0][0] === $intercepted[0][0]
        && $native[0][1] === $intercepted[0][1] && $native[1] === $intercepted[1];
    echo $form, ': ', json_encode($native), ' ',
        $native === $wrapped && $interceptionMatches ? 'match' : 'MISMATCH', "\n";
}

class MagicReference
{
    public int $value = 1;

    public function &__call(string $name, array $args): mixed
    {
        return $this->value;
    }
}
function returnCall(object $object, MagicReference $target, string $form): int
{
    if ($form === 'direct') {
        $alias = &$object->missing();
    } else {
        $callable = $form === 'first class'
            ? $object->missing(...)
            : Closure::fromCallable([$object, 'missing']);
        $alias = &$callable();
    }
    $alias = 99;
    return $target->value;
}
foreach (['direct', 'first class', 'closure'] as $form) {
    $native = new MagicReference;
    $wrapped = new MagicReference;
    $intercepted = new MagicReference;
    $expected = returnCall($native, $native, $form);
    $actual = returnCall(Proxy::wrap($wrapped), $wrapped, $form);
    $interceptedResult = returnCall(Proxy::wrap(
        $intercepted,
        methodInterceptor: function &(Invocation $call) {
            return $call->proceed(...$call->args());
        },
    ), $intercepted, $form);
    echo "return $form: $expected ", $expected === $actual && $expected === $interceptedResult
        ? 'match'
        : 'MISMATCH', "\n";
}

$value = 1;
$mock = Proxy::mock(MagicReference::class, methodInterceptor: function &(Invocation $call) use (&$value) {
    return $value;
});
$alias = &$mock->missing();
$alias = 7;
echo "mock return: $value\n";
unset($alias);

class MagicValue
{
    public function __call(string $name, array $args): mixed
    {
        return null;
    }
}
$value = ['original'];
$mock = Proxy::mock(MagicValue::class, methodInterceptor: function &(Invocation $call) use (&$value) {
    return $value;
});
$copy = $mock->missing();
$copy[] = 'copy';
echo 'value return: ', json_encode($value), "\n";
?>
--EXPECT--
direct: [["missing",0,false],1] match
unpack: [["missing",0,false],1] match
array: [["missing",0,true],99] match
named array: [["missing","item",true],99] match
first class: [["missing",0,false],1] match
closure: [["missing",0,false],1] match
return direct: 99 match
return first class: 99 match
return closure: 99 match
mock return: 7
value return: ["original"]
