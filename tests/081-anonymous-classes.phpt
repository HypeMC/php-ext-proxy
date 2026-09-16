--TEST--
Anonymous classes can be wrapped and mocked
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;
use Proxy\Invocation;
use Proxy\PropertyInvocation as PI;
use Proxy\PropertyOperation as Op;

interface Greeter
{
    public function greet(string $n): string;
}

$anonymousTarget = new class('x') implements Greeter {
    public int $calls = 0;

    public function __construct(public string $prefix)
    {
    }

    final public function greet(string $n): string
    {
        $this->calls++;
        return "{$this->prefix}:hello $n";
    }
};

echo "--- wrap anonymous instance ---\n";
$wrapped = Proxy::wrap(
    $anonymousTarget,
    methodInterceptor: fn (Invocation $call) => strtoupper($call->proceed(...$call->args())),
    propertyInterceptor: fn (PI $propertyCall) => $propertyCall->property() === 'prefix' && $propertyCall->operation() === Op::GET
        ? 'P'
        : $propertyCall->proceed(...($propertyCall->operation() === Op::SET
            ? [$propertyCall->value()]
            : [])),
);
var_dump($wrapped instanceof Greeter, $wrapped instanceof $anonymousTarget, Proxy::isProxy($wrapped));
var_dump($wrapped->greet('bob'), $anonymousTarget->calls, $wrapped->prefix, $wrapped->calls);
var_dump($wrapped::class === get_class($anonymousTarget), strtok($wrapped::class, "\0"));
var_dump(
    get_class($wrapped),
    (new ReflectionObject($wrapped))->isAnonymous(),
    (new ReflectionObject($wrapped))->getParentClass()->isAnonymous()
);

echo "--- mock anonymous class by name ---\n";
$mock = Proxy::mock(
    get_class($anonymousTarget),
    methodInterceptor: fn (Invocation $call) => 'mock ' . $call->args()[0]
);
var_dump($mock instanceof Greeter, $mock instanceof $anonymousTarget, $mock->greet('z'));
try {
    $mock->calls;
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}

echo "--- two different anonymous classes get distinct proxy classes ---\n";
$firstClass = new class {
    public function f()
    {
        return 1;
    }
};
$secondClass = new class {
    public function f()
    {
        return 2;
    }
};
$firstProxy = Proxy::wrap($firstClass);
$secondProxy = Proxy::wrap($secondClass);
var_dump(
    $firstProxy->f(),
    $secondProxy->f(),
    get_class($firstProxy) !== get_class($secondProxy),
    $firstProxy instanceof $secondClass
);
echo "--- anonymous class extending a final-method parent, statics ---\n";
$countableClass = new class extends ArrayObject {
    const K = 'k';

    public static function s()
    {
        return 's';
    }
};
$countableProxy = Proxy::wrap($countableClass, methodInterceptor: fn () => 42);
var_dump(
    count($countableProxy),
    $countableProxy::K,
    $countableProxy::s(),
    $countableProxy instanceof ArrayObject
);
echo "done\n";
--EXPECT--
--- wrap anonymous instance ---
bool(true)
bool(true)
bool(true)
string(11) "X:HELLO BOB"
int(1)
string(1) "P"
int(1)
bool(true)
string(17) "Greeter@anonymous"
string(24) "Greeter@anonymousProxy_1"
bool(false)
bool(true)
--- mock anonymous class by name ---
bool(true)
bool(true)
string(6) "mock z"
Proxy\Exception\UnconfiguredProperty: Unconfigured GET of property Greeter@anonymous::$calls on mock
--- two different anonymous classes get distinct proxy classes ---
int(1)
int(2)
bool(true)
bool(false)
--- anonymous class extending a final-method parent, statics ---
int(42)
string(1) "k"
string(1) "s"
bool(true)
done
