--TEST--
Compound property operations with interceptors, shadowed private methods, traits, generators, dumps, unserialize
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;
use Proxy\Invocation;
use Proxy\PropertyInvocation as PI;
use Proxy\PropertyOperation as Op;

echo "--- compound ops with property interceptor ---\n";
class Bag
{
    public int $n = 1;
    public string $s = 'a';
    public array $arr = ['k' => 1];
    public ?stdClass $obj = null;

    public function __construct()
    {
        $this->obj = new stdClass;
    }
}
$bagTarget = new Bag();
$ops = [];
$bagProxy = Proxy::wrap($bagTarget, propertyInterceptor: function &(PI $propertyCall) use (&$ops) {
    $ops[] = $propertyCall->operation()->name . ':' . $propertyCall->property();
    if ($propertyCall->operation() === Op::SET) {
        return $propertyCall->proceed($propertyCall->value());
    }
    return $propertyCall->proceed();
});
$bagProxy->n++;
$bagProxy->n += 5;
$bagProxy->s .= 'b';
$bagProxy->arr['x'] = 2;
$bagProxy->arr[] = 3;
$bagProxy->obj->y = 9;
unset($bagProxy->arr['k']);
foreach ($bagProxy->arr as &$v) {
    $v *= 10;
}
unset($v);
$ref = &$bagProxy->n;
$ref = 100;
var_dump($bagTarget->n, $bagTarget->s, $bagTarget->arr, $bagTarget->obj->y);
echo implode(' ', $ops), "\n";
echo "--- shadowed private method called from ancestor scope ---\n";
class Base
{
    private function tag(): string
    {
        return 'base';
    }

    public function viaBase(Base $o): string
    {
        return $o->tag();
    }
}
class Child extends Base
{
    public function tag(): string
    {
        return 'child';
    }
}
$childProxy = Proxy::wrap(new Child(), methodInterceptor: function (Invocation $call) {
    echo "[", $call->method(), "@", get_class($call->target()), "]";
    return $call->proceed(...$call->args());
});
var_dump($childProxy->tag(), $childProxy->viaBase($childProxy));
echo "--- traits, static::, self:: inside original ---\n";
trait Greets
{
    public function greet(): string
    {
        return static::class . '/' . self::class . '/' . get_class($this);
    }
}
class Greeter
{
    use Greets;
}
$greeterProxy = Proxy::wrap(new Greeter());
var_dump($greeterProxy->greet());
echo "--- interface property hooks (8.4 interface props) ---\n";
interface Named
{
    public string $name { get; }
}
$namedMock = Proxy::mock(
    Named::class,
    propertyInterceptor: fn (PI $propertyCall) => $propertyCall->operation() === Op::GET
        ? 'mocked name'
        : null
);
var_dump(
    $namedMock->name,
    $namedMock instanceof Named,
    (new ReflectionObject($namedMock))->hasProperty('name')
);
echo "--- enums, DNF, callable types, nullable defaults ---\n";
enum Suit: string
{
    case H = 'h';
    case S = 's';
}
interface I1
{
}
interface I2
{
}
class Both implements I1, I2
{
}
class Types
{
    public function e(Suit $s, ?Both $b = null, (I1&I2)|null $d = null, callable $cb = null): string
    {
        return $s->value . ($b ? 'B' : '-') . ($d ? 'D' : '-') . ($cb ? 'C' : '-');
    }
}
$typesProxy = Proxy::wrap(
    new Types(),
    methodInterceptor: fn (Invocation $call) => $call->proceed(...$call->args())
);
var_dump(
    $typesProxy->e(Suit::H),
    $typesProxy->e(Suit::S, new Both, new Both, 'strlen'),
    $typesProxy->e(s: Suit::H, cb: 'strlen')
);
try {
    $typesProxy->e('h');
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
try {
    $typesProxy->e(Suit::H, new stdClass);
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
echo "--- generators ---\n";
class Gen
{
    public function items(): Generator
    {
        yield 1;
        yield 2;
    }
}
$generatorProxy = Proxy::wrap(new Gen(), methodInterceptor: function (Invocation $call) {
    foreach ($call->proceed() as $v) {
        yield $v * 2;
    }
});
var_dump(iterator_to_array($generatorProxy->items()));
echo "--- nested dispatch from interceptor ---\n";
class Calc
{
    public function a(): int
    {
        return 1;
    }

    public function b(): int
    {
        return 2;
    }
}
$calculatorProxy = Proxy::wrap(new Calc(), methodInterceptor: fn (Invocation $call) => match ($call->method()) {
    'a' => $call->proceed() + $call->proxy()->b(),
    'b' => $call->proceed() * 10,
    default => $call->proceed(...$call->args())
});
var_dump($calculatorProxy->a());
echo "--- exceptions propagate ---\n";
class Thrower
{
    public function boom(): void
    {
        throw new DomainException('orig');
    }
}
$throwingProxy = Proxy::wrap(new Thrower(), methodInterceptor: function (Invocation $call) {
    try {
        return $call->proceed();
    } finally {
        echo "[finally]";
    }
});
try {
    $throwingProxy->boom();
} catch (DomainException $error) {
    echo get_class($error), ": ", $error->getMessage(), " @", basename($error->getFile()), ":", $error->getLine(), "\n";
}
$throwingMock = Proxy::mock(Thrower::class, methodInterceptor: function () {
    throw new LengthException('from interceptor');
});
try {
    $throwingMock->boom();
} catch (LengthException $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
echo "--- dumps ---\n";
print_r($bagProxy);
echo "\n";
var_export($bagProxy);
echo "\n";
debug_zval_dump(Proxy::mock(Bag::class));
var_dump(Proxy::mock(Bag::class));
echo "--- unserialize of proxy class name ---\n";
try {
    var_dump(unserialize('O:' . strlen(get_class($bagProxy)) . ':"' . get_class($bagProxy) . '":0:{}'));
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
try {
    var_dump(unserialize('C:' . strlen(get_class($bagProxy)) . ':"' . get_class($bagProxy) . '":0:{}'));
} catch (Throwable $error) {
    echo get_class($error), ": ", $error->getMessage(), "\n";
}
echo "--- proxy of proxy target ---\n";
var_dump(Proxy::target(Proxy::wrap($bagTarget)) === $bagTarget);
echo "done\n";
--EXPECTF--

Deprecated: Types::e(): Implicitly marking parameter $cb as nullable is deprecated, the explicit nullable type must be used instead in %s080-edge-cases.php on line %d
--- compound ops with property interceptor ---
int(100)
string(2) "ab"
array(2) {
  ["x"]=>
  int(20)
  [0]=>
  int(30)
}
int(9)
GET:n SET:n GET:n SET:n GET:s SET:s GET:arr GET:arr GET:obj GET:arr GET:arr GET:n
--- shadowed private method called from ancestor scope ---
[tag@Child][viaBase@Child][tag@Child]string(5) "child"
string(4) "base"
--- traits, static::, self:: inside original ---
string(23) "Greeter/Greeter/Greeter"
--- interface property hooks (8.4 interface props) ---
string(11) "mocked name"
bool(true)
bool(true)
--- enums, DNF, callable types, nullable defaults ---
string(4) "h---"
string(4) "sBDC"
string(4) "h--C"
TypeError: Types::e(): Argument #%d ($s) must be of type Suit, string given, called in %s080-edge-cases.php on line %d
TypeError: Types::e(): Argument #%d ($b) must be of type ?Both, stdClass given, called in %s080-edge-cases.php on line %d
--- generators ---
array(2) {
  [0]=>
  int(2)
  [1]=>
  int(4)
}
--- nested dispatch from interceptor ---
int(21)
--- exceptions propagate ---
[finally]DomainException: orig @080-edge-cases.php:179
LengthException: from interceptor
--- dumps ---
BagProxy_1 Object
(
    [n] => 100
    [s] => ab
    [arr] => Array
        (
            [x] => 20
            [0] => 30
        )

    [obj] => stdClass Object
        (
            [y] => 9
        )

)

\BagProxy_1::__set_state(array(
   'n' => 100,
   's' => 'ab',
   'arr' => 
  array (
    'x' => 20,
    0 => 30,
  ),
   'obj' => 
  (object) array(
     'y' => 9,
  ),
))
object(BagProxy_1)#%d (0) refcount(%d){
  ["n"]=>
  uninitialized(int)
  ["s"]=>
  uninitialized(string)
  ["arr"]=>
  uninitialized(array)
  ["obj"]=>
  uninitialized(?stdClass)
}
object(BagProxy_1)#%d (0) {
  ["n"]=>
  uninitialized(int)
  ["s"]=>
  uninitialized(string)
  ["arr"]=>
  uninitialized(array)
  ["obj"]=>
  uninitialized(?stdClass)
}
--- unserialize of proxy class name ---
Proxy\Exception\UnsupportedOperation: Proxy class BagProxy_1 cannot be instantiated directly; use Proxy\Proxy::mock() or Proxy\Proxy::wrap()

Warning: Class BagProxy_1 has no unserializer in %s080-edge-cases.php on line %d
Proxy\Exception\UnsupportedOperation: Proxy class BagProxy_1 cannot be instantiated directly; use Proxy\Proxy::mock() or Proxy\Proxy::wrap()
--- proxy of proxy target ---
bool(true)
done
