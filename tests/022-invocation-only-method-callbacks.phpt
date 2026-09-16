--TEST--
Method interceptors receive only Invocation without collisions with named method arguments
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;
use Proxy\Invocation;

function check(mixed $actual, mixed $expected): void
{
    if ($actual !== $expected) {
        throw new RuntimeException(var_export([$actual, $expected], true));
    }
}

class NamedArguments
{
    public array $seen = [];

    public function collect(mixed ...$values): array
    {
        $this->seen[] = $values;
        return $values;
    }

    public function __call(string $name, array $args): array
    {
        return [$name, $args];
    }
}

$expected = (new NamedArguments)->collect(10, call: 20, invocation: 30, args: 40, values: 50);
foreach (['wrap', 'mock'] as $mode) {
    foreach (['call', 'invocation'] as $parameterName) {
        $interceptorCalls = 0;
        $callback = $parameterName === 'call'
            ? function (Invocation $call) use (&$interceptorCalls): array {
                check(func_num_args(), 1);
                ++$interceptorCalls;
                return $call->hasOriginal() ? $call->proceed(...$call->args()) : $call->args();
            }
            : function (Invocation $invocation) use (&$interceptorCalls): array {
                check(func_num_args(), 1);
                ++$interceptorCalls;
                return $invocation->hasOriginal()
                    ? $invocation->proceed(...$invocation->args())
                    : $invocation->args();
            };
        $config = ['methodInterceptor' => $callback];
        $proxy = $mode === 'wrap'
            ? Proxy::wrap(new NamedArguments, ...$config)
            : Proxy::mock(NamedArguments::class, ...$config);
        check($proxy->collect(10, call: 20, invocation: 30, args: 40, values: 50), $expected);
        check($interceptorCalls, 1);
        echo "named variadics: $mode, callback parameter $parameterName\n";
    }
}

$target = new NamedArguments;
$interceptorCalls = 0;
$proxy = Proxy::wrap($target, methodInterceptor: function (Invocation $call) use (&$interceptorCalls): array {
    check(func_num_args(), 1);
    ++$interceptorCalls;
    $original = ['call' => 'initial', 'invocation' => 'outer'];
    check($call->args(), $original);
    $first = $call->proceed(call: 'first', invocation: 'one');
    check($call->args(), $original);
    $second = $call->proceed(call: 'second', invocation: 'two');
    check($call->args(), $original);
    return [$first, $second];
});
check($proxy->collect(call: 'initial', invocation: 'outer'), [
    ['call' => 'first', 'invocation' => 'one'],
    ['call' => 'second', 'invocation' => 'two'],
]);
check($interceptorCalls, 1);
check(count($target->seen), 2);
echo "repeated proceed and current arguments: wrap\n";

foreach (['wrap', 'mock'] as $mode) {
    $callback = function (Invocation $call): array {
        check(func_num_args(), 1);
        return $call->hasOriginal()
            ? $call->proceed(...$call->args())
            : [$call->method(), $call->args()];
    };
    $proxy = $mode === 'wrap'
        ? Proxy::wrap(new NamedArguments, methodInterceptor: $callback)
        : Proxy::mock(NamedArguments::class, methodInterceptor: $callback);
    check($proxy->dynamic(call: 1, invocation: 2), (new NamedArguments)->dynamic(call: 1, invocation: 2));
    echo "magic method named forwarding: $mode\n";
}

// Omitted arguments remain omitted, even when the callback's old signature
// would previously have supplied defaults for them.
$proxy = Proxy::wrap(new NamedArguments, methodInterceptor: function (Invocation $call): array {
    check(func_num_args(), 1);
    check($call->args(), ['call' => 'discarded']);
    return $call->proceed();
});
check($proxy->collect(call: 'discarded'), []);
echo "explicit zero-argument forwarding\n";
?>
--EXPECT--
named variadics: wrap, callback parameter call
named variadics: wrap, callback parameter invocation
named variadics: mock, callback parameter call
named variadics: mock, callback parameter invocation
repeated proceed and current arguments: wrap
magic method named forwarding: wrap
magic method named forwarding: mock
explicit zero-argument forwarding
