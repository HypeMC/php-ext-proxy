--TEST--
Factories expose only the target type and two optional interceptors, rejecting removed maps
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;
use Proxy\Invocation;
use Proxy\PropertyInvocation;

class FactoryTarget
{
    public int $value = 1;

    public function run(): int
    {
        return 2;
    }
}

foreach (['mock', 'wrap'] as $factory) {
    $reflection = new ReflectionMethod(Proxy::class, $factory);
    $parameters = $reflection->getParameters();
    echo $factory, ': ', json_encode(array_map(fn ($parameter) => $parameter->getName(), $parameters)), "\n";
    var_dump(
        count($parameters) === 3,
        $reflection->getNumberOfRequiredParameters() === 1,
        (string) $parameters[1]->getType() === '?callable',
        (string) $parameters[2]->getType() === '?callable',
        $parameters[1]->getDefaultValue() === null,
        $parameters[2]->getDefaultValue() === null
    );

    $target = $factory === 'mock' ? FactoryTarget::class : new FactoryTarget;
    $proxy = Proxy::$factory(
        $target,
        fn (Invocation $call) => $call->method() === 'run' ? 20 : $call->proceed(...$call->args()),
        fn (PropertyInvocation $call) => $call->property() === 'value' ? 10 : $call->proceed()
    );
    echo 'positional: ', $proxy->run(), ',', $proxy->value, "\n";
    $proxy = Proxy::$factory(
        $target,
        propertyInterceptor: fn (PropertyInvocation $call) => 30,
        methodInterceptor: fn (Invocation $call) => 40
    );
    echo 'named: ', $proxy->run(), ',', $proxy->value, "\n";
    foreach (['methods', 'properties'] as $removed) {
        try {
            Proxy::$factory($target, ...[$removed => []]);
            echo "removed argument accepted\n";
        } catch (Error $error) {
            echo $removed, ': ', $error->getMessage(), "\n";
        }
    }
    try {
        Proxy::$factory($target, null, null, []);
        echo "fourth argument accepted\n";
    } catch (ArgumentCountError $error) {
        echo "fourth argument rejected\n";
    }
    foreach (['methodInterceptor', 'propertyInterceptor'] as $parameter) {
        try {
            Proxy::$factory($target, ...[$parameter => ['run' => fn () => 1]]);
            echo "map accepted\n";
        } catch (TypeError $error) {
            echo "$parameter map rejected\n";
        }
    }
}
?>
--EXPECT--
mock: ["class","methodInterceptor","propertyInterceptor"]
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
positional: 20,10
named: 40,30
methods: Unknown named parameter $methods
properties: Unknown named parameter $properties
fourth argument rejected
methodInterceptor map rejected
propertyInterceptor map rejected
wrap: ["target","methodInterceptor","propertyInterceptor"]
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
positional: 20,10
named: 40,30
methods: Unknown named parameter $methods
properties: Unknown named parameter $properties
fourth argument rejected
methodInterceptor map rejected
propertyInterceptor map rejected
