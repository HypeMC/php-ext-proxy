--TEST--
Typed named variadic arguments are checked before interception and preserve coercion and references
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;
use Proxy\Invocation;

class NamedNumbers
{
    public function numbers(int ...$values): array
    {
        return $values;
    }

    public function mutate(int &...$values): array
    {
        foreach ($values as &$value) {
            $value += 10;
        }
        return $values;
    }
}

// Keep the main test weak so the next section can observe input coercion.
$strictCalls = eval('declare(strict_types=1); return [
    static fn ($object) => $object->numbers(two: "2"),
    static fn ($object) => $object->numbers(1, two: "2"),
    static fn ($object) => $object->numbers(values: "2"),
];');

echo "--- strict named arguments ---\n";
foreach (['native', 'wrap', 'mock'] as $mode) {
    $interceptorCalls = 0;
    $config = [
        'methodInterceptor' => function (Invocation $call) use (&$interceptorCalls) {
            ++$interceptorCalls;
            return $call->args();
        },
    ];
    $object = match ($mode) {
        'native' => new NamedNumbers,
        'wrap' => Proxy::wrap(new NamedNumbers, ...$config),
        'mock' => Proxy::mock(NamedNumbers::class, ...$config),
    };
    foreach ($strictCalls as $invoke) {
        try {
            $invoke($object);
            echo "$mode: accepted\n";
        } catch (Throwable $e) {
            echo "$mode: ", get_class($e), ":$interceptorCalls\n";
        }
    }
}

echo "--- weak coercion and argument mapping ---\n";
echo 'native:', json_encode((new NamedNumbers)->numbers('1', two: '2')), "\n";
foreach (['wrap', 'mock'] as $mode) {
    $config = [
        'methodInterceptor' => function (Invocation $call) {
            $values = $call->args();
            echo 'interceptor args:', json_encode($call->args()), "\n";
            echo 'interceptor values:', json_encode($values), "\n";
            return $call->hasOriginal() ? $call->proceed(...$values) : $values;
        },
    ];
    $object = $mode === 'wrap'
        ? Proxy::wrap(new NamedNumbers, ...$config)
        : Proxy::mock(NamedNumbers::class, ...$config);
    echo "$mode\n";
    $result = $object->numbers('1', two: '2');
    echo 'result:', json_encode($result), "\n";
    $result = $object->numbers(one: '3', two: '4');
    echo 'result:', json_encode($result), "\n";
}

echo "--- referenced variadics through interceptor ---\n";
foreach (['native', 'wrap', 'mock'] as $mode) {
    $config = [
        'methodInterceptor' => function (Invocation $call) {
            $values = $call->args();
            foreach ($values as &$value) {
                $value += 3;
            }
            return $call->hasOriginal() ? $call->proceed(...$values) : $values;
        },
    ];
    $object = match ($mode) {
        'native' => new NamedNumbers,
        'wrap' => Proxy::wrap(new NamedNumbers, ...$config),
        'mock' => Proxy::mock(NamedNumbers::class, ...$config),
    };
    $first = '1';
    $second = '2';
    $result = $object->mutate($first, tail: $second);
    echo "$mode:", json_encode($result), ':', json_encode([$first, $second]), "\n";
}
?>
--EXPECT--
--- strict named arguments ---
native: TypeError:0
native: TypeError:0
native: TypeError:0
wrap: TypeError:0
wrap: TypeError:0
wrap: TypeError:0
mock: TypeError:0
mock: TypeError:0
mock: TypeError:0
--- weak coercion and argument mapping ---
native:{"0":1,"two":2}
wrap
interceptor args:{"0":1,"two":2}
interceptor values:{"0":1,"two":2}
result:{"0":1,"two":2}
interceptor args:{"one":3,"two":4}
interceptor values:{"one":3,"two":4}
result:{"one":3,"two":4}
mock
interceptor args:{"0":1,"two":2}
interceptor values:{"0":1,"two":2}
result:{"0":1,"two":2}
interceptor args:{"one":3,"two":4}
interceptor values:{"one":3,"two":4}
result:{"one":3,"two":4}
--- referenced variadics through interceptor ---
native:{"0":11,"tail":12}:[11,12]
wrap:{"0":14,"tail":15}:[14,15]
mock:{"0":4,"tail":5}:[4,5]
