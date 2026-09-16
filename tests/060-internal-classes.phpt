--TEST--
Proxies of internal classes: ArrayObject, DateTimeImmutable, exceptions, SplStack; rejected internal finals
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;
use Proxy\Invocation;

echo "--- wrap ArrayObject ---\n";
$arrayTarget = new ArrayObject(['x' => 1, 'y' => 2]);
$arrayProxy = Proxy::wrap(
    $arrayTarget,
    methodInterceptor: fn (Invocation $c) => $c->method() === 'count'
        ? $c->proceed() + 100
        : $c->proceed(...$c->args())
);
var_dump(
    count($arrayProxy),
    $arrayProxy['x'],
    isset($arrayProxy['y']),
    $arrayProxy->getArrayCopy(),
    iterator_to_array($arrayProxy),
    get_parent_class($arrayProxy)
);
$arrayProxy['z'] = 3;
var_dump($arrayTarget['z']);
foreach ($arrayProxy as $k => $v) {
    echo "$k=$v ";
}
echo "\n";
var_dump(json_encode($arrayProxy), (array) $arrayProxy);
echo "--- mock ArrayObject (uninitialized internal state stays safe) ---\n";
$arrayMock = Proxy::mock(
    ArrayObject::class,
    methodInterceptor: fn (Invocation $c) => $c->method() === 'count'
        ? 7
        : $c->proceed(...$c->args())
);
var_dump(count($arrayMock), $arrayMock instanceof ArrayObject, $arrayMock instanceof Countable);
try {
    $arrayMock->getArrayCopy();
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
try {
    var_dump(iterator_count($arrayMock));
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
echo "--- wrap DateTimeImmutable ---\n";
$dateTarget = new DateTimeImmutable('2024-01-02 03:04:05', new DateTimeZone('UTC'));
$dateProxy = Proxy::wrap(
    $dateTarget,
    methodInterceptor: fn (Invocation $c) => $c->method() === 'format'
        ? 'F:' . $c->proceed(...$c->args())
        : $c->proceed(...$c->args())
);
var_dump($dateProxy->format('Y-m-d'), $dateProxy->getTimestamp(), $dateProxy instanceof DateTimeInterface);
var_dump($dateProxy->modify('+1 day')->format('Y-m-d'), get_class($dateProxy->modify('+1 day')));
var_dump($dateProxy == $dateTarget, $dateProxy > new DateTimeImmutable('2000-01-01'));
try {
    var_dump(date_diff($dateProxy, $dateTarget)->days);
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
try {
    var_dump($dateTarget->diff($dateProxy)->days);
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
echo "--- mock DateTimeImmutable passed to internal code ---\n";
$dateMock = Proxy::mock(DateTimeImmutable::class, methodInterceptor: fn () => 'mocked');
var_dump($dateMock->format('Y'));
try {
    var_dump($dateTarget->diff($dateMock));
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
try {
    var_dump(date_format($dateMock, 'Y'));
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
echo "--- exceptions ---\n";
class MyEx extends RuntimeException
{
}
$exceptionTarget = new MyEx('boom', 42);
$exceptionProxy = Proxy::wrap(
    $exceptionTarget,
    methodInterceptor: fn (Invocation $c) => $c->method() === 'getMessage'
        ? strtoupper($c->proceed())
        : $c->proceed(...$c->args())
);
var_dump($exceptionProxy->getMessage(), $exceptionProxy->getCode(), $exceptionProxy instanceof Throwable);
try {
    throw $exceptionProxy;
} catch (MyEx $caught) {
    var_dump($caught === $exceptionProxy, $caught->getMessage());
}
$exceptionMock = Proxy::mock(MyEx::class, methodInterceptor: fn () => 'mock message');
var_dump($exceptionMock->getMessage());
echo "--- rejected internal finals ---\n";
foreach ([Closure::class, WeakMap::class, Generator::class, Fiber::class] as $c) {
    try {
        Proxy::mock($c);
        echo "$c ok\n";
    } catch (Throwable $error) {
        echo "$c: ", get_class($error), ": ", $error->getMessage(), "\n";
    }
}
try {
    Proxy::wrap(fn () => 1);
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
echo "--- wrap SplObjectStorage / SplStack ---\n";
$stack = new SplStack();
$stack->push(1);
$stack->push(2);
$stackProxy = Proxy::wrap($stack);
var_dump(count($stackProxy), $stackProxy->top(), iterator_to_array($stackProxy));
echo "--- mock Countable/Iterator internal ifaces ---\n";
$countableMock = Proxy::mock(Countable::class, methodInterceptor: fn () => 9);
var_dump(count($countableMock));
echo "done\n";
--EXPECT--
--- wrap ArrayObject ---
int(102)
int(1)
bool(true)
array(2) {
  ["x"]=>
  int(1)
  ["y"]=>
  int(2)
}
array(2) {
  ["x"]=>
  int(1)
  ["y"]=>
  int(2)
}
string(11) "ArrayObject"
int(3)
x=1 y=2 z=3 
string(19) "{"x":1,"y":2,"z":3}"
array(3) {
  ["x"]=>
  int(1)
  ["y"]=>
  int(2)
  ["z"]=>
  int(3)
}
--- mock ArrayObject (uninitialized internal state stays safe) ---
int(7)
bool(true)
bool(true)
Proxy\Exception\UnconfiguredMethod: Call to unconfigured method ArrayObject::getArrayCopy() on mock
Proxy\Exception\UnconfiguredMethod: Call to unconfigured method ArrayObject::getIterator() on mock
--- wrap DateTimeImmutable ---
string(12) "F:2024-01-02"
int(1704164645)
bool(true)
string(10) "2024-01-03"
string(17) "DateTimeImmutable"
bool(false)
bool(false)
DateObjectError: Object of type DateTimeImmutableProxy_2 (inheriting DateTimeImmutable) has not been correctly initialized by calling parent::__construct() in its constructor
DateObjectError: Object of type DateTimeImmutableProxy_2 (inheriting DateTimeImmutable) has not been correctly initialized by calling parent::__construct() in its constructor
--- mock DateTimeImmutable passed to internal code ---
string(6) "mocked"
DateObjectError: Object of type DateTimeImmutableProxy_2 (inheriting DateTimeImmutable) has not been correctly initialized by calling parent::__construct() in its constructor
DateObjectError: Object of type DateTimeImmutableProxy_2 (inheriting DateTimeImmutable) has not been correctly initialized by calling parent::__construct() in its constructor
--- exceptions ---
string(4) "BOOM"
int(42)
bool(true)
bool(true)
string(4) "BOOM"
string(12) "mock message"
--- rejected internal finals ---
Closure: Proxy\Exception\UnsupportedProxyType: Internal final class Closure cannot be proxied
WeakMap: Proxy\Exception\UnsupportedProxyType: Internal final class WeakMap cannot be proxied
Generator: Proxy\Exception\UnsupportedProxyType: Internal final class Generator cannot be proxied
Fiber: Proxy\Exception\UnsupportedProxyType: Internal final class Fiber cannot be proxied
Proxy\Exception\UnsupportedProxyType: Internal final class Closure cannot be proxied
--- wrap SplObjectStorage / SplStack ---
int(2)
int(2)
array(2) {
  [1]=>
  int(2)
  [0]=>
  int(1)
}
--- mock Countable/Iterator internal ifaces ---
int(9)
done
