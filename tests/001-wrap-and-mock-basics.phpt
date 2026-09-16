--TEST--
Proxy::wrap() and Proxy::mock() basics: interception, final methods, statics, introspection
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;
use Proxy\Invocation;

final class HttpClient
{
    public int $calls = 0;

    public function __construct(private string $base = 'http://x')
    {
    }

    final public function send(string $url, int $timeout = 5): string
    {
        $this->calls++;
        return "real:{$this->base}{$url}:{$timeout}";
    }

    public function getTimeout(): int
    {
        return 42;
    }

    public function me(): static
    {
        return $this;
    }

    public static function create(): static
    {
        return new static();
    }

    const TIMEOUT = 7;

    public static int $instances = 3;
}

$client = new HttpClient();
$proxy = Proxy::wrap(
    $client,
    methodInterceptor: function (Invocation $call) {
        echo "interceptor: ", $call->method(), "(", json_encode($call->args()), ")\n";
        if ($call->method() === 'send' && str_contains($call->args()[0], 'test')) {
            return 'fake:' . $call->args()[0];
        }
        return $call->proceed(...$call->args());
    },
);

var_dump($proxy instanceof HttpClient);
var_dump(Proxy::isProxy($proxy), Proxy::isProxy($client));
var_dump($proxy::class);
var_dump(Proxy::target($proxy) === $client);
var_dump(get_class($proxy));
var_dump($proxy->send('/test'));
var_dump($proxy->send('/live', 9));
var_dump($proxy->send(timeout: 3, url: '/named'));
var_dump($proxy->getTimeout());
var_dump($proxy->me() === $client);
var_dump($proxy->calls, $client->calls);
$proxy->calls = 10;
var_dump($client->calls);
var_dump($proxy::TIMEOUT, $proxy::$instances);
try {
    $proxy::create();
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
var_dump(method_exists($proxy, 'send'), is_callable([$proxy, 'send']));
var_dump(call_user_func([$proxy, 'send'], '/cuf'));
var_dump(array_map([$proxy, 'getTimeout'], [1]));
try {
    var_dump($proxy->send(123));
    echo "coerced ok\n";
} catch (TypeError $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
$mock = Proxy::mock(
    HttpClient::class,
    methodInterceptor: fn (Invocation $call) => $call->method() === 'send'
        ? 'mock:' . $call->args()[0]
        : $call->proceed(...$call->args())
);
var_dump($mock->send('/m'));
try {
    $mock->getTimeout();
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
try {
    $mock->calls;
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
try {
    clone $proxy;
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
try {
    serialize($proxy);
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
try {
    Proxy::mock(Traversable::class);
    echo "iface mock ok\n";
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
var_dump((new ReflectionObject($proxy))->getParentClass()->getName());
echo "done\n";
--EXPECT--
bool(true)
bool(true)
bool(false)
string(10) "HttpClient"
bool(true)
string(17) "HttpClientProxy_1"
interceptor: send(["\/test"])
string(10) "fake:/test"
interceptor: send(["\/live",9])
string(20) "real:http://x/live:9"
interceptor: send(["\/named",3])
string(21) "real:http://x/named:3"
interceptor: getTimeout([])
int(42)
interceptor: me([])
bool(true)
int(2)
int(2)
int(10)
int(7)
int(3)
Proxy\Exception\UnsupportedOperation: Proxy class HttpClientProxy_1 cannot be instantiated directly; use Proxy\Proxy::mock() or Proxy\Proxy::wrap()
bool(true)
bool(true)
interceptor: send(["\/cuf"])
string(19) "real:http://x/cuf:5"
interceptor: getTimeout([1])
array(1) {
  [0]=>
  int(42)
}
interceptor: send(["123"])
string(18) "real:http://x123:5"
coerced ok
string(7) "mock:/m"
Proxy\Exception\UnconfiguredMethod: Call to unconfigured method HttpClient::getTimeout() on mock
Proxy\Exception\UnconfiguredProperty: Unconfigured GET of property HttpClient::$calls on mock
Proxy\Exception\UnsupportedOperation: Proxy objects of class HttpClientProxy_1 cannot be cloned
Proxy\Exception\UnsupportedOperation: Proxy objects of class HttpClientProxy_1 cannot be serialized
iface mock ok
string(10) "HttpClient"
done
