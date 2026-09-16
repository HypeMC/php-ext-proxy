--TEST--
Strict mocks always throw UnconfiguredMethod/Property, including repeated and deferred proceed()
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;
use Proxy\Invocation;
use Proxy\PropertyInvocation;
use Proxy\PropertyOperation;
use Proxy\Exception\UnconfiguredMethod;
use Proxy\Exception\UnconfiguredProperty;
use Proxy\Exception\NoOriginalImplementation;

class Exhaustion
{
    public int $value = 1;

    public function run(int $value = 7): int
    {
        throw new LogicException('A mock must never execute its original method');
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function exhausted(callable $operation, string $exception, string $message): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        check(get_class($error) === $exception, 'Wrong exception: ' . get_class($error));
        check($error->getMessage() === $message, 'Wrong message: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Unconfigured mock operation did not throw');
}

function forward(Invocation|PropertyInvocation $call): mixed
{
    if ($call instanceof Invocation) {
        return $call->proceed(...$call->args());
    }
    return $call->operation() === PropertyOperation::SET
        ? $call->proceed($call->value())
        : $call->proceed();
}

function propertyOperation(object $proxy, PropertyOperation $operation): mixed
{
    switch ($operation) {
        case PropertyOperation::GET:
            return $proxy->value;
        case PropertyOperation::SET:
            return $proxy->value = 5;
        case PropertyOperation::ISSET:
            return isset($proxy->value);
        case PropertyOperation::UNSET:
            unset($proxy->value);
            return null;
    }
}

foreach (['none', 'interceptor'] as $mode) {
    $hasInterceptor = $mode === 'interceptor';
    foreach ([null, ...PropertyOperation::cases()] as $operation) {
        $savedInvocation = null;
        $interceptorCalls = 0;
        $interceptor = $hasInterceptor
            ? function (Invocation|PropertyInvocation $call) use (&$savedInvocation, &$interceptorCalls) {
                check(!$call->hasOriginal(), 'Mock reported an original implementation');
                check($call->target() === null, 'Mock reported a target');
                $savedInvocation = $call;
                ++$interceptorCalls;
                return forward($call);
            }
            : null;
        if ($operation === null) {
            $proxy = Proxy::mock(Exhaustion::class, methodInterceptor: $interceptor);
            $action = fn () => $proxy->run(9);
            $exception = UnconfiguredMethod::class;
            $message = 'Call to unconfigured method Exhaustion::run() on mock';
        } else {
            $proxy = Proxy::mock(Exhaustion::class, propertyInterceptor: $interceptor);
            $action = fn () => propertyOperation($proxy, $operation);
            $exception = UnconfiguredProperty::class;
            $message = 'Unconfigured ' . $operation->name . ' of property Exhaustion::$value on mock';
        }
        exhausted($action, $exception, $message);
        $expectedCalls = $hasInterceptor ? 1 : 0;
        check($interceptorCalls === $expectedCalls, 'Wrong interceptor call count');

        // Retain the interceptor's invocation and retry after the
        // intercepted operation throws; exhaustion must keep the same error.
        if ($savedInvocation !== null) {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                exhausted(fn () => forward($savedInvocation), $exception, $message);
                check($interceptorCalls === $expectedCalls, 'Deferred proceed restarted the interceptor');
            }
        }
    }
    echo "$mode: method and GET/SET/ISSET/UNSET exhausted consistently\n";
}

// Retrying inside an active callback must also preserve the same exception.
foreach ([null, ...PropertyOperation::cases()] as $operation) {
    $attempts = $entries = 0;
    $retry = function (Invocation|PropertyInvocation $call) use (&$attempts, &$entries) {
        $entries++;
        try {
            $attempts++;
            return forward($call);
        } catch (UnconfiguredMethod|UnconfiguredProperty $error) {
            $attempts++;
            return forward($call);
        }
    };
    if ($operation === null) {
        $proxy = Proxy::mock(Exhaustion::class, methodInterceptor: $retry);
        exhausted(
            fn () => $proxy->run(),
            UnconfiguredMethod::class,
            'Call to unconfigured method Exhaustion::run() on mock'
        );
    } else {
        $proxy = Proxy::mock(Exhaustion::class, propertyInterceptor: $retry);
        exhausted(
            fn () => propertyOperation($proxy, $operation),
            UnconfiguredProperty::class,
            'Unconfigured ' . $operation->name . ' of property Exhaustion::$value on mock'
        );
    }
    check($attempts === 2, 'Callback did not retry exactly once');
    check($entries === 1, 'Retry restarted the interceptor');
}
echo "active retries: method and GET/SET/ISSET/UNSET exhausted consistently\n";
check(class_exists(NoOriginalImplementation::class), 'Public exception class was removed');
check(is_subclass_of(NoOriginalImplementation::class, Throwable::class), 'Invalid exception class');
echo "NoOriginalImplementation remains available\n";
?>
--EXPECT--
none: method and GET/SET/ISSET/UNSET exhausted consistently
interceptor: method and GET/SET/ISSET/UNSET exhausted consistently
active retries: method and GET/SET/ISSET/UNSET exhausted consistently
NoOriginalImplementation remains available
