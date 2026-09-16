--TEST--
Interface and abstract class mocks, reflection, rejected types and invalid interceptors
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;
use Proxy\Invocation;

interface LoggerInterface
{
    const LEVEL = 'info';

    public function log(string $msg, array $ctx = []): void;

    public static function make(): static;
}
interface Repo extends Countable
{
    public function find(int $id): ?object;
}
abstract class AbstractClient
{
    abstract protected function doSend(string $u): string;

    public function send(string $u): string
    {
        return 'sent:' . $this->doSend($u);
    }

    public function ping(): string
    {
        return 'pong';
    }
}

echo "--- interface mock ---\n";
$logger = Proxy::mock(LoggerInterface::class, methodInterceptor: function (Invocation $call) {
    $args = $call->args();
    $msg = $args[0];
    $ctx = $args[1] ?? [];
    echo "LOG[$msg]", json_encode($ctx), "\n";
});
var_dump($logger instanceof LoggerInterface, $logger::class, $logger::LEVEL, LoggerInterface::LEVEL);
$logger->log('hello', ['a' => 1]);
$logger->log('x');
try {
    $logger->log();
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
try {
    $logger::make();
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
$strictLogger = Proxy::mock(LoggerInterface::class);
try {
    $strictLogger->log('x');
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
echo "--- interface extending Countable ---\n";
$repository = Proxy::mock(Repo::class, methodInterceptor: fn (Invocation $call) => match ($call->method()) {
    'count' => 3,
    'find' => $call->args()[0] === 1 ? new stdClass : null,
    default => $call->proceed(...$call->args())
});
var_dump(
    count($repository),
    $repository instanceof Countable,
    $repository->find(1) instanceof stdClass,
    $repository->find(2)
);
echo "--- abstract class mock ---\n";
$abstractMock = Proxy::mock(
    AbstractClient::class,
    methodInterceptor: fn (Invocation $call) => $call->method() === 'send'
        ? 'mocked:' . $call->args()[0]
        : $call->proceed(...$call->args())
);
var_dump($abstractMock instanceof AbstractClient, $abstractMock->send('/u'));
try {
    $abstractMock->ping();
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
try {
    $abstractMock->doSend('/u');
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
echo "--- reflection ---\n";
$loggerReflection = new ReflectionObject($logger);
var_dump(
    $loggerReflection->getName() === get_class($logger),
    $loggerReflection->isFinal(),
    $loggerReflection->getInterfaceNames(),
    $loggerReflection->getParentClass(),
    $loggerReflection->hasMethod('log'),
    $loggerReflection->getMethod('log')->getNumberOfParameters(),
    $loggerReflection->getMethod('log')->getDeclaringClass()->getName()
);
var_dump(array_map(fn ($m) => $m->getName(), $loggerReflection->getMethods()));
$abstractReflection = new ReflectionObject($abstractMock);
var_dump(
    $abstractReflection->getParentClass()->getName(),
    $abstractReflection->isAbstract(),
    $abstractReflection->isInstantiable(),
    $abstractReflection->hasMethod('doSend'),
    $abstractReflection->getMethod('doSend')->isProtected(),
    $abstractReflection->getMethod('doSend')->isAbstract()
);
var_dump(
    (string) $abstractReflection->getMethod("send")->getReturnType(),
    $abstractReflection->getMethod("send")->hasPrototype(),
    $abstractReflection->getMethod("send")->isInternal(),
    $abstractReflection->getMethod("send")->getParameters()[0]->getName()
);
echo "--- rejected types ---\n";
enum Status: string
{
    case Active = 'a';
}
trait T1
{
}
foreach ([
    Status::class,
    T1::class,
    'Nope\Missing',
    Throwable::class,
    DateTimeInterface::class,
    UnitEnum::class
] as $type) {
    try {
        Proxy::mock($type);
        echo "$type: ok\n";
    } catch (Throwable $error) {
        echo "$type: ", get_class($error), ": ", $error->getMessage(), "\n";
    }
}
try {
    Proxy::wrap(Status::Active);
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
try {
    Proxy::wrap($logger);
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
try {
    Proxy::mock(get_class($logger));
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
echo "--- invalid interceptors ---\n";
foreach (['methodInterceptor', 'propertyInterceptor'] as $parameter) {
    try {
        Proxy::mock(LoggerInterface::class, ...[$parameter => []]);
        echo "ok\n";
    } catch (Throwable $error) {
        echo $parameter, ': ', get_class($error), "\n";
    }
}
try {
    Proxy::mock(LoggerInterface::class, 'not_callable');
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
echo "--- class reuse & naming ---\n";
var_dump(
    get_class(Proxy::mock(LoggerInterface::class)) === get_class($logger),
    class_exists(get_class($logger)),
    (new ReflectionClass(get_class($logger)))->getName()
);
try {
    new (get_class($logger))();
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
try {
    (new ReflectionClass(get_class($logger)))->newInstanceWithoutConstructor()->log('x');
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
echo "done\n";
--EXPECTF--
--- interface mock ---
bool(true)
string(15) "LoggerInterface"
string(4) "info"
string(4) "info"
LOG[hello]{"a":1}
LOG[x][]
ArgumentCountError: Too few arguments to function LoggerInterface::log(), 0 passed in %s030-interfaces-abstract-reflection.php on line %d and at least 1 expected
Error: Cannot call abstract method LoggerInterface::make()
Proxy\Exception\UnconfiguredMethod: Call to unconfigured method LoggerInterface::log() on mock
--- interface extending Countable ---
int(3)
bool(true)
bool(true)
NULL
--- abstract class mock ---
bool(true)
string(9) "mocked:/u"
Proxy\Exception\UnconfiguredMethod: Call to unconfigured method AbstractClient::ping() on mock
Error: Call to protected method AbstractClient::doSend() from global scope
--- reflection ---
bool(true)
bool(true)
array(1) {
  [0]=>
  string(15) "LoggerInterface"
}
bool(false)
bool(true)
int(2)
string(15) "LoggerInterface"
array(2) {
  [0]=>
  string(3) "log"
  [1]=>
  string(4) "make"
}
string(14) "AbstractClient"
bool(false)
bool(true)
bool(true)
bool(true)
bool(false)
string(6) "string"
bool(false)
bool(true)
string(1) "u"
--- rejected types ---
Status: Proxy\Exception\UnsupportedProxyType: Enum Status cannot be proxied
T1: Proxy\Exception\UnsupportedProxyType: Trait T1 cannot be proxied
Nope\Missing: Proxy\Exception\InvalidProxyTarget: Class "Nope\Missing" does not exist
Throwable: Proxy\Exception\UnsupportedProxyType: Interface Throwable cannot be implemented by user classes and cannot be proxied
DateTimeInterface: Proxy\Exception\UnsupportedProxyType: Interface DateTimeInterface cannot be implemented by user classes and cannot be proxied
UnitEnum: Proxy\Exception\UnsupportedProxyType: Interface UnitEnum cannot be implemented by user classes and cannot be proxied
Proxy\Exception\UnsupportedProxyType: Enum case Status::Active cannot be proxied
Proxy\Exception\InvalidProxyTarget: Object of class LoggerInterfaceProxy_1 is already a proxy and cannot be wrapped
Proxy\Exception\InvalidProxyTarget: LoggerInterfaceProxy_1 is a proxy class and cannot be proxied again
--- invalid interceptors ---
methodInterceptor: TypeError
propertyInterceptor: TypeError
TypeError: Proxy\Proxy::mock(): Argument #%d ($methodInterceptor) must be a valid callback or null, function "not_callable" not found or invalid function name
--- class reuse & naming ---
bool(true)
bool(true)
string(22) "LoggerInterfaceProxy_1"
Proxy\Exception\UnsupportedOperation: Proxy class LoggerInterfaceProxy_1 cannot be instantiated directly; use Proxy\Proxy::mock() or Proxy\Proxy::wrap()
Proxy\Exception\UnsupportedOperation: Proxy class LoggerInterfaceProxy_1 cannot be instantiated directly; use Proxy\Proxy::mock() or Proxy\Proxy::wrap()
done
