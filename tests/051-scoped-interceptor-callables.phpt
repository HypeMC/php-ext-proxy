--TEST--
Interceptor callables retain registration scope, called scope, and object lifetimes
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;
use Proxy\Invocation;
use Proxy\PropertyInvocation;

class CallbackTarget
{
    public string $name = 'target';

    public function run(string $suffix): string
    {
        return 'target' . $suffix;
    }
}

class ScopedCallbacks
{
    public static ?WeakReference $instance = null;

    public static function create(bool $mock): object
    {
        $provider = new static;
        self::$instance = WeakReference::create($provider);
        $config = [
            'methodInterceptor' => [$provider, 'method'],
            'propertyInterceptor' => [$provider, 'property'],
        ];
        return $mock
            ? Proxy::mock(CallbackTarget::class, ...$config)
            : Proxy::wrap(new CallbackTarget, ...$config);
    }

    public static function createStatic(bool $mock): object
    {
        $config = [
            'methodInterceptor' => [static::class, 'staticMethod'],
            'propertyInterceptor' => [static::class, 'staticProperty'],
        ];
        return $mock
            ? Proxy::mock(CallbackTarget::class, ...$config)
            : Proxy::wrap(new CallbackTarget, ...$config);
    }

    private static function staticMethod(Invocation $call): string
    {
        return static::class . ':' . ($call->hasOriginal()
            ? $call->proceed(...$call->args())
            : 'mock' . $call->arg(0));
    }

    protected function method(Invocation $call): string
    {
        $suffix = $call->args()[0];
        return static::class . ':instance:' . ($call->hasOriginal()
            ? $call->proceed($suffix)
            : 'mock' . $suffix);
    }

    private static function staticProperty(PropertyInvocation $prop): string
    {
        return static::class . ':' . ($prop->hasOriginal() ? $prop->proceed() : 'mock');
    }

    protected function property(PropertyInvocation $prop): string
    {
        return static::class . ':instance:' . ($prop->hasOriginal() ? $prop->proceed() : 'mock');
    }
}

class ChildCallbacks extends ScopedCallbacks
{
}

foreach ([false, true] as $mock) {
    $proxy = ChildCallbacks::create($mock);
    var_dump(ScopedCallbacks::$instance->get() instanceof ChildCallbacks);
    echo $proxy->run('!'), "\n", $proxy->name, "\n";
    unset($proxy);
    gc_collect_cycles();
    var_dump(ScopedCallbacks::$instance->get());
}

foreach ([false, true] as $mock) {
    $proxy = ChildCallbacks::createStatic($mock);
    echo $proxy->run('!'), "\n", $proxy->name, "\n";
}

echo "--- invalid outside registration scope ---\n";
try {
    Proxy::mock(CallbackTarget::class, methodInterceptor: [ScopedCallbacks::class, 'staticMethod']);
} catch (Throwable $e) {
    echo get_class($e), "\n";
}

echo "--- magic and invokable callables ---\n";
class MagicCallbacks
{
    public function __call(string $name, array $args): string
    {
        return $name . ':' . $args[0]->args()[0];
    }

    public static function __callStatic(string $name, array $args): string
    {
        return $name . ':' . $args[0]->proceed(...$args[0]->args());
    }
}
$provider = new MagicCallbacks;
$weak = WeakReference::create($provider);
$proxy = Proxy::mock(CallbackTarget::class, methodInterceptor: [$provider, 'instance']);
unset($provider);
echo $proxy->run('one'), "\n", $proxy->run('two'), "\n";
var_dump($weak->get() instanceof MagicCallbacks);
unset($proxy);
gc_collect_cycles();
var_dump($weak->get());

$proxy = Proxy::wrap(new CallbackTarget, methodInterceptor: [MagicCallbacks::class, 'static']);
echo $proxy->run('!'), "\n";

$proxy = Proxy::mock(CallbackTarget::class, methodInterceptor: new class {
    public function __invoke(Invocation $call): string
    {
        return 'invokable:' . $call->args()[0];
    }
});
echo $proxy->run('ok'), "\n";

$closure = fn (Invocation $call): string => 'closure:' . $call->args()[0];
$proxy = Proxy::mock(CallbackTarget::class, methodInterceptor: [$closure, '__invoke']);
unset($closure);
echo $proxy->run('ok'), "\n";
?>
--EXPECT--
bool(true)
ChildCallbacks:instance:target!
ChildCallbacks:instance:target
NULL
bool(true)
ChildCallbacks:instance:mock!
ChildCallbacks:instance:mock
NULL
ChildCallbacks:target!
ChildCallbacks:target
ChildCallbacks:mock!
ChildCallbacks:mock
--- invalid outside registration scope ---
TypeError
--- magic and invokable callables ---
instance:one
instance:two
bool(true)
NULL
static:target!
invokable:ok
closure:ok
