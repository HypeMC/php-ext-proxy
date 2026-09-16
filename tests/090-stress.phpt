--TEST--
Many intercepted calls and many proxies
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;
use Proxy\Invocation;
use Proxy\PropertyInvocation as PI;
use Proxy\PropertyOperation as Op;
class Acc
{
    public int $total = 0;

    public function add(int $x): int
    {
        $this->total += $x;
        return $this->total;
    }

    public function chain(int $depth): int
    {
        return $depth <= 0 ? 0 : 1 + $this->chain($depth - 1);
    }
}
$target = new Acc();
$proxy = Proxy::wrap(
    $target,
    methodInterceptor: fn (Invocation $call) => $call->proceed(...$call->args()),
    propertyInterceptor: fn (PI $propertyCall) => $propertyCall->operation() === Op::SET
        ? $propertyCall->proceed($propertyCall->value())
        : $propertyCall->proceed(),
);
for ($i = 0; $i < 100000; $i++) {
    $proxy->add(1);
}
var_dump($target->total);
for ($i = 0; $i < 100000; $i++) {
    $proxy->total = $proxy->total + 1;
}
var_dump($target->total);
var_dump($proxy->chain(50));
$mocks = [];
for ($i = 0; $i < 2000; $i++) {
    $mocks[] = Proxy::mock(Acc::class, methodInterceptor: fn (Invocation $call) => $call->args()[0]);
}
var_dump(count($mocks), $mocks[1999]->add(5), memory_get_usage() < 64 * 1024 * 1024);
echo "done\n";
--EXPECT--
int(100000)
int(200000)
int(50)
int(2000)
int(5)
bool(true)
done
