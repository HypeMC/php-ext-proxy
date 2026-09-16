--TEST--
Method contracts use their declaring callable scope and preserve internal argument count and null coercion behavior
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Invocation;
use Proxy\Proxy;

function outcome(callable $call): mixed
{
    try {
        return $call();
    } catch (Throwable $error) {
        return get_class($error);
    }
}
function compare(string $label, mixed $native, mixed $wrapped, mixed $mock): void
{
    echo $label, ': ', json_encode($native), ' ',
        $native === $wrapped && $native === $mock ? 'match' : 'MISMATCH', "\n";
}
class CallableContract
{
    private function secret(): int
    {
        return 42;
    }

    public function accept(callable $callback): int
    {
        return $callback();
    }

    public function produce(): callable
    {
        return [$this, 'secret'];
    }

    public function foreign(object $other): callable
    {
        return [$other, 'hidden'];
    }
}
class ForeignScope
{
    private function hidden(): int
    {
        return 99;
    }

    public static function accept(object $receiver): mixed
    {
        return outcome(fn () => $receiver->accept([new self, 'hidden']));
    }

    public static function produce(object $receiver): mixed
    {
        return outcome(fn () => $receiver->foreign(new self));
    }
}
$target = new CallableContract;
$wrap = Proxy::wrap($target);
$mock = Proxy::mock(CallableContract::class, methodInterceptor: fn (Invocation $call) => match ($call->method()) {
    'accept' => 42,
    'produce' => [$target, 'secret'],
    'foreign' => [$call->arg(0), 'hidden'],
    default => $call->proceed(...$call->args()),
});
compare(
    'private callable parameter',
    $target->accept([$target, 'secret']),
    $wrap->accept([$target, 'secret']),
    $mock->accept([$target, 'secret'])
);
compare(
    'private callable return',
    $target->produce() === [$target, 'secret'],
    $wrap->produce() === [$target, 'secret'],
    $mock->produce() === [$target, 'secret']
);
compare(
    'foreign private parameter',
    ForeignScope::accept($target),
    ForeignScope::accept($wrap),
    ForeignScope::accept($mock)
);
compare(
    'foreign private return',
    ForeignScope::produce($target),
    ForeignScope::produce($wrap),
    ForeignScope::produce($mock)
);

$calls = 0;
$callback = function (Invocation $call) use (&$calls): mixed {
    ++$calls;
    return 5;
};
foreach ([
    'extra count' => [new ArrayObject, 'count', [1]],
    'extra format' => [new DateTime, 'format', ['Y', 'extra']],
    'missing format' => [new DateTime, 'format', []],
] as $label => [$target, $method, $args]) {
    $wrap = Proxy::wrap($target, methodInterceptor: $callback);
    $mock = Proxy::mock($target::class, methodInterceptor: $callback);
    compare(
        $label,
        outcome(fn () => $target->$method(...$args)),
        outcome(fn () => $wrap->$method(...$args)),
        outcome(fn () => $mock->$method(...$args))
    );
}
echo "invalid argument callbacks: $calls\n";

function nullCall(object $object, string $method, array $args): array
{
    $warnings = [];
    set_error_handler(function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = [$level, $message];
        return true;
    });
    try {
        $result = outcome(fn () => $object->$method(...$args));
        if ($result instanceof DateInterval) {
            $result = $result->format('%R%a');
        }
        return [$result, $warnings];
    } finally {
        restore_error_handler();
    }
}
foreach ([
    'string' => [new DateTime('2000-01-01'), 'format', [null], 0],
    'int' => [new ArrayObject, 'setFlags', [null], 0],
    'bool' => [new DateTime('2000-01-01'), 'diff', [new DateTime('2000-01-02'), null], 1],
] as $type => [$target, $method, $args, $index]) {
    $native = nullCall($target, $method, $args);
    $seen = [];
    $wrap = Proxy::wrap($target, methodInterceptor: function (Invocation $call) use (&$seen, $index): mixed {
        $seen[] = $call->args()[$index];
        return $call->proceed(...$call->args());
    });
    $wrapped = nullCall($wrap, $method, $args);
    $mock = Proxy::mock($target::class, methodInterceptor: function (Invocation $call) use (&$seen, $index, $target, $method): mixed {
        $seen[] = $call->args()[$index];
        return $target->$method(...$call->args());
    });
    $mocked = nullCall($mock, $method, $args);
    echo "null $type: ", $native === $wrapped && $native === $mocked ? 'match' : 'MISMATCH',
        ' warnings=', count($native[1]), ' args=', json_encode($seen), "\n";
}

// A deprecation converted to an exception must prevent interception.
$calls = 0;
$wrap = Proxy::wrap(new DateTime, methodInterceptor: function (Invocation $call) use (&$calls): string {
    ++$calls;
    return '';
});
set_error_handler(function (): never {
    throw new RuntimeException('deprecated');
});
try {
    compare(
        'throwing deprecation',
        outcome(fn () => (new DateTime)->format(null)),
        outcome(fn () => $wrap->format(null)),
        outcome(fn () => Proxy::mock(DateTime::class, methodInterceptor: function (Invocation $call) use (&$calls): string {
            ++$calls;
            return '';
        })->format(null))
    );
} finally {
    restore_error_handler();
}
echo "deprecation callbacks: $calls\n";

// strict_types still rejects null before entering any interceptor.
$strictCall = eval('declare(strict_types=1); return static fn ($object) => $object->format(null);');
compare(
    'strict null',
    outcome(fn () => $strictCall(new DateTime)),
    outcome(fn () => $strictCall($wrap)),
    outcome(fn () => $strictCall(Proxy::mock(DateTime::class, methodInterceptor: fn (Invocation $call) => '')))
);
class UserScalar
{
    public function take(string $value): string
    {
        return $value;
    }
}
$user = new UserScalar;
compare(
    'user null',
    outcome(fn () => $user->take(null)),
    outcome(fn () => Proxy::wrap($user)->take(null)),
    outcome(fn () => Proxy::mock(UserScalar::class, methodInterceptor: fn (Invocation $call) => '')->take(null))
);
?>
--EXPECT--
private callable parameter: 42 match
private callable return: true match
foreign private parameter: "TypeError" match
foreign private return: "TypeError" match
extra count: "ArgumentCountError" match
extra format: "ArgumentCountError" match
missing format: "ArgumentCountError" match
invalid argument callbacks: 0
null string: match warnings=1 args=["",""]
null int: match warnings=1 args=[0,0]
null bool: match warnings=1 args=[false,false]
throwing deprecation: "RuntimeException" match
deprecation callbacks: 0
strict null: "TypeError" match
user null: "TypeError" match
