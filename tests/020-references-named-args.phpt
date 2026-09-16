--TEST--
References, by-reference returns, named arguments, defaults, variadics, multiple proceed()
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;
use Proxy\Invocation;

class Svc
{
    const DEF = 'cdef';

    public array $data = ['k' => 'v'];

    public function mutate(string &$value, int $times = 2): string
    {
        $value = str_repeat($value, $times);
        return "done";
    }

    public function &ref(): array
    {
        return $this->data;
    }

    public function opt(string $a, string $b = self::DEF, int ...$rest): string
    {
        return "$a|$b|" . implode(',', $rest);
    }

    public function extra(string $a): array
    {
        return func_get_args();
    }

    public function variadic(string $first, mixed ...$rest): array
    {
        return [$first, $rest];
    }

    public function fail(int $n): string
    {
        if ($n < 2) {
            throw new RuntimeException("fail$n");
        }
        return "ok$n";
    }
}

echo "--- by-ref param through interceptor ---\n";
$service = new Svc();
$proxy = Proxy::wrap(
    $service,
    methodInterceptor: function (Invocation $call) {
        $args = $call->args();
        if ($call->method() === 'mutate') {
            $args[0] .= '!';
        }
        return $call->proceed(...$args);
    },
);
$value = 'ab';
var_dump($proxy->mutate($value), $value);
$repeatedValue = 'x';
var_dump($proxy->mutate($repeatedValue, 3), $repeatedValue);
echo "--- by-ref return ---\n";
$detachedResult = &$proxy->ref();
$detachedResult['added'] = 1;
var_dump($service->data);
$referenceProxy = Proxy::wrap($service, methodInterceptor: function &(Invocation $call) {
    return $call->proceed();
});
$referenceResult = &$referenceProxy->ref();
$referenceResult['added2'] = 2;
var_dump(array_keys($service->data));
echo "--- named args, gaps and defaults ---\n";
var_dump($proxy->opt('a'));
var_dump($proxy->opt(a: 'A'));
var_dump($proxy->opt('a', 'b', 1, 2, 3));
$argumentProxy = Proxy::wrap($service, methodInterceptor: function (Invocation $call) {
    $args = $call->args();
    $a = $args[0];
    $b = $args[1] ?? 'idef';
    $rest = $args;
    unset($rest[0], $rest[1]);
    return "int:$a|$b|" . implode(',', $rest) . '|' . json_encode($args);
});
var_dump($argumentProxy->opt('a'));
var_dump($argumentProxy->opt(a: 'x', rest: 5));
try {
    var_dump($argumentProxy->opt(nope: 1));
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
var_dump($proxy->variadic('f', 1, 2, x: 3));
echo "--- extra args reach original ---\n";
var_dump($proxy->extra('a', 'b', 'c'));
echo "--- multiple proceed (retry) ---\n";
$interceptorCalls = 0;
$retryProxy = Proxy::wrap(
    $service,
    methodInterceptor: function (Invocation $call) use (&$interceptorCalls) {
        ++$interceptorCalls;
        try {
            return $call->proceed(...$call->args());
        } catch (RuntimeException $error) {
            echo "retry after ", $error->getMessage(), "\n";
            return $call->proceed(2);
        }
    },
);
var_dump($retryProxy->fail(1), $interceptorCalls);
echo "--- invocation api ---\n";
$inspectedProxy = Proxy::wrap($service, methodInterceptor: function (Invocation $call) {
    var_dump(
        $call->method(),
        $call->class(),
        $call->hasOriginal(),
        $call->arg(0),
        $call->proxy() === $GLOBALS['inspectedProxy'],
        $call->target() === $GLOBALS['service']
    );
    try {
        $call->arg(9);
    } catch (Throwable $error) {
        echo get_class($error), ": ", $error->getMessage(), "\n";
    }
    return $call->proceed(...$call->args());
});
var_dump($inspectedProxy->opt('z'));
echo "--- late proceed ---\n";
$savedInvocation = null;
$deferredProxy = Proxy::wrap($service, methodInterceptor: function (Invocation $call) use (&$savedInvocation) {
    $savedInvocation = $call;
    return 'deferred';
});
var_dump($deferredProxy->opt('l'));
var_dump($savedInvocation->proceed('later'));
var_dump($savedInvocation->args());
echo "--- mock: proceed without original ---\n";
$mock = Proxy::mock(Svc::class, methodInterceptor: fn (Invocation $call) => $call->proceed('x'));
try {
    $mock->opt('a');
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
var_dump(Proxy::mock(
    Svc::class,
    methodInterceptor: fn (Invocation $call) => var_export($call->hasOriginal(), true)
)->opt('a'));
echo "done\n";
--EXPECTF--
--- by-ref param through interceptor ---
string(4) "done"
string(6) "ab!ab!"
string(4) "done"
string(6) "x!x!x!"
--- by-ref return ---

Notice: Only variables should be assigned by reference in %s on line %d
array(1) {
  ["k"]=>
  string(1) "v"
}
array(2) {
  [0]=>
  string(1) "k"
  [1]=>
  string(6) "added2"
}
--- named args, gaps and defaults ---
string(7) "a|cdef|"
string(7) "A|cdef|"
string(9) "a|b|1,2,3"
string(17) "int:a|idef||["a"]"
string(31) "int:x|idef|5|{"0":"x","rest":5}"
ArgumentCountError: Too few arguments to function Svc::opt(), 0 passed in %s020-references-named-args.php on line %d and at least 1 expected
array(2) {
  [0]=>
  string(1) "f"
  [1]=>
  array(3) {
    [0]=>
    int(1)
    [1]=>
    int(2)
    ["x"]=>
    int(3)
  }
}
--- extra args reach original ---
array(3) {
  [0]=>
  string(1) "a"
  [1]=>
  string(1) "b"
  [2]=>
  string(1) "c"
}
--- multiple proceed (retry) ---
retry after fail1
string(3) "ok2"
int(1)
--- invocation api ---
string(3) "opt"
string(3) "Svc"
bool(true)
string(1) "z"
bool(true)
bool(true)
ValueError: Proxy\Invocation::arg(): Argument #%d ($index) must be a valid argument index
string(7) "z|cdef|"
--- late proceed ---
string(8) "deferred"
string(11) "later|cdef|"
array(1) {
  [0]=>
  string(5) "later"
}
--- mock: proceed without original ---
Proxy\Exception\UnconfiguredMethod: Call to unconfigured method Svc::opt() on mock
string(5) "false"
done
