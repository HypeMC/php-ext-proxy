--TEST--
Callables resolved by the engine: shadowed private methods follow PHP's selection, __call names dispatch through the chain
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Invocation;
use Proxy\Proxy;

function attempt(string $label, callable $operation): void
{
    echo $label, ': ';
    try {
        echo $operation(), "\n";
    } catch (Throwable $error) {
        echo get_class($error), ': ', $error->getMessage(), "\n";
    }
}

echo "--- private ancestor method shadowed by a public redeclaration ---\n";
class Base
{
    public string $tag = 'base';

    private function identify(int $v): string
    {
        return "Base::identify($v) this=" . get_class($this) . " tag={$this->tag}";
    }

    public static function direct(self $o, int $v): string
    {
        return $o->identify($v);
    }

    public static function viaCallUserFunc(self $o, int $v): string
    {
        return call_user_func([$o, 'identify'], $v);
    }

    public static function viaArrayMap(self $o, int $v): string
    {
        return array_map([$o, 'identify'], [$v])[0];
    }

    public static function viaFirstClass(self $o, int $v): string
    {
        return ($o->identify(...))($v);
    }

    public static function viaFromCallable(self $o, int $v): string
    {
        return Closure::fromCallable([$o, 'identify'])($v);
    }
}
class Child extends Base
{
    public function identify(int $v): string
    {
        return "Child::identify($v)";
    }
}
$wrap = fn () => Proxy::wrap(
    new Child,
    methodInterceptor: fn (Invocation $call) => 'INT:' . $call->proceed(...$call->args())
);
foreach (['direct', 'viaCallUserFunc', 'viaArrayMap', 'viaFirstClass'] as $form) {
    attempt("native $form", fn () => Base::$form(new Child, 1));
    attempt("proxy $form", fn () => Base::$form($wrap(), 1));
}
// Callables the engine resolves itself select the ancestor's private method
// like PHP does, and run its original body on the proxy without interception.
// This narrow exclusion is approved to preserve JIT, separately from reflection.
attempt('proxy viaFromCallable', fn () => Base::viaFromCallable($wrap(), 1));
attempt('proxy global call_user_func', fn () => call_user_func([$wrap(), 'identify'], 1));
attempt(
    'mock viaCallUserFunc',
    fn () => Base::viaCallUserFunc(
        Proxy::mock(Child::class, methodInterceptor: fn () => 'MOCK'),
        1
    )
);
attempt('strict mock viaArrayMap', fn () => Base::viaArrayMap(Proxy::mock(Child::class), 1));

echo "--- approved exclusion also runs property-free bodies on mocks ---\n";
class CallbackBase
{
    private function ping(): string
    {
        return Proxy::isProxy($this) ? 'original on proxy' : 'original on target';
    }

    public static function invoke(self $object, bool $closure): string
    {
        return $closure ? Closure::fromCallable([$object, 'ping'])() : call_user_func([$object, 'ping']);
    }
}
class CallbackChild extends CallbackBase
{
    public function ping(): string
    {
        return 'child';
    }
}
$callbacks = 0;
$strict = Proxy::mock(CallbackChild::class);
$configured = Proxy::mock(CallbackChild::class, methodInterceptor: function (Invocation $call) use (&$callbacks) {
    ++$callbacks;
    return 'intercepted';
});
foreach ([false, true] as $closure) {
    attempt(
        $closure ? 'strict Closure::fromCallable' : 'strict call_user_func',
        fn () => CallbackBase::invoke($strict, $closure)
    );
    attempt(
        $closure ? 'configured Closure::fromCallable' : 'configured call_user_func',
        fn () => CallbackBase::invoke($configured, $closure)
    );
}
echo "excluded callback interceptions: $callbacks\n";
attempt('ordinary public call remains intercepted', fn () => $configured->ping());

echo "--- private methods on both levels and a private static ancestor ---\n";
class PA
{
    private function m(): string
    {
        return 'PA::m';
    }

    public static function via($o)
    {
        return $o->m() . '|' . call_user_func([$o, 'm']) . '|' . var_export(is_callable([$o, 'm']), true);
    }
}
class PB extends PA
{
    private function m(): string
    {
        return 'PB::m';
    }
}
attempt(
    'two privates from ancestor',
    fn () => PA::via(Proxy::wrap(
        new PB,
        methodInterceptor: fn (Invocation $call) => 'INT:' . $call->proceed(...$call->args())
    ))
);
class UA
{
    private static function u(): string
    {
        return 'UA::u';
    }

    public static function via($o)
    {
        return $o->u() . '|' . call_user_func([$o, 'u']);
    }
}
class UB extends UA
{
    public function u(): string
    {
        return 'UB::u';
    }
}
attempt(
    'private static shadow',
    fn () => UA::via(Proxy::wrap(
        new UB,
        methodInterceptor: fn (Invocation $call) => 'INT:' . $call->proceed(...$call->args())
    ))
);
class RA
{
    private function m(): string
    {
        return 'RA::m';
    }

    public static function refl($o)
    {
        return (new ReflectionMethod($o, 'm'))->invoke($o);
    }

    public static function filter($o)
    {
        return new CallbackFilterIterator(new ArrayIterator([1]), [$o, 'keep']);
    }

    private function keep($v): bool
    {
        echo '[RA::keep]';
        return true;
    }
}
class RB extends RA
{
    public function m(): string
    {
        return 'RB::m';
    }

    public function keep($v): bool
    {
        echo '[RB::keep]';
        return true;
    }
}
attempt(
    'generated-class reflection from ancestor',
    fn () => RA::refl(Proxy::wrap(
        new RB,
        methodInterceptor: fn (Invocation $call) => 'INT:' . $call->proceed(...$call->args())
    ))
);
attempt(
    'callable stored in ancestor scope',
    fn () => count(iterator_to_array(RA::filter(Proxy::wrap(new RB))))
);

echo "--- names resolved by __call() ---\n";
class Dynamic
{
    public function __call($name, $args)
    {
        return "__call($name," . json_encode($args) . ")";
    }
}
$wrap = fn () => Proxy::wrap(
    new Dynamic,
    methodInterceptor: fn (Invocation $call) => '[' . $call->method() . ']' . ($call->method() === 'dyn'
        ? 'DYN:' . json_encode($call->args())
        : $call->proceed(...$call->args())),
);
attempt('call', fn () => $wrap()->dyn(1));
attempt('first-class callable', function () use ($wrap) {
    $f = $wrap()->dyn(...);
    return $f(1, 2);
});
attempt('first-class callable twice', function () use ($wrap) {
    $f = $wrap()->dyn(...);
    return $f(1) . '|' . $f(2);
});
attempt('Closure::fromCallable', fn () => Closure::fromCallable([$wrap(), 'dyn'])(1));
attempt('call_user_func', fn () => call_user_func([$wrap(), 'dyn'], 1));
attempt('unconfigured name with named argument', function () use ($wrap) {
    $f = $wrap()->other(...);
    return $f('a', k: 'v');
});
attempt('strict mock first-class callable', fn () => (Proxy::mock(Dynamic::class)->dyn(...))(1));
attempt('strict mock call', fn () => Proxy::mock(Dynamic::class)->dyn(1));
echo "done\n";
?>
--EXPECTF--
--- private ancestor method shadowed by a public redeclaration ---
native direct: Base::identify(1) this=Child tag=base
proxy direct: INT:Base::identify(1) this=Child tag=base
native viaCallUserFunc: Base::identify(1) this=Child tag=base
proxy viaCallUserFunc: Base::identify(1) this=ChildProxy_%s tag=base
native viaArrayMap: Base::identify(1) this=Child tag=base
proxy viaArrayMap: Base::identify(1) this=ChildProxy_%s tag=base
native viaFirstClass: Base::identify(1) this=Child tag=base
proxy viaFirstClass: INT:Base::identify(1) this=Child tag=base
proxy viaFromCallable: Base::identify(1) this=ChildProxy_%s tag=base
proxy global call_user_func: INT:Child::identify(1)
mock viaCallUserFunc: Proxy\Exception\UnconfiguredProperty: Unconfigured GET of property Child::$tag on mock
strict mock viaArrayMap: Proxy\Exception\UnconfiguredProperty: Unconfigured GET of property Child::$tag on mock
--- approved exclusion also runs property-free bodies on mocks ---
strict call_user_func: original on proxy
configured call_user_func: original on proxy
strict Closure::fromCallable: original on proxy
configured Closure::fromCallable: original on proxy
excluded callback interceptions: 0
ordinary public call remains intercepted: intercepted
--- private methods on both levels and a private static ancestor ---
two privates from ancestor: INT:PA::m|PA::m|true
private static shadow: UA::u|UA::u
generated-class reflection from ancestor: INT:RB::m
callable stored in ancestor scope: [RA::keep]1
--- names resolved by __call() ---
call: [dyn]DYN:[1]
first-class callable: [dyn]DYN:[1,2]
first-class callable twice: [dyn]DYN:[1]|[dyn]DYN:[2]
Closure::fromCallable: [dyn]DYN:[1]
call_user_func: [dyn]DYN:[1]
unconfigured name with named argument: [other]__call(other,{"0":"a","k":"v"})
strict mock first-class callable: Proxy\Exception\UnconfiguredMethod: Call to unconfigured method Dynamic::dyn() on mock
strict mock call: Proxy\Exception\UnconfiguredMethod: Call to unconfigured method Dynamic::dyn() on mock
done
