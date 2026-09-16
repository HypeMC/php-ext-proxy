--TEST--
Property hooks preserve widened input types, eligibility, conditional reads, and one getter evaluation for empty
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;
use Proxy\PropertyInvocation as PI;
use Proxy\PropertyOperation as Op;

function forwardProperty(PI $call): mixed {
    return $call->operation() === Op::SET
        ? $call->proceed($call->value()) : $call->proceed();
}
class WideSetter {
    public int $length = 0 {
        set(int|string $value) {
            $this->length = is_string($value) ? strlen($value) : $value;
        }
    }
    public int $total = 0 {
        set(mixed $value) {
            $this->total = is_array($value) ? count($value) : (int) $value;
        }
    }
}
echo "widened setters\n";
foreach ([false, true] as $wrapped) {
    $target = new WideSetter;
    $object = $wrapped ? Proxy::wrap($target, propertyInterceptor: 'forwardProperty') : $target;
    $object->length = 'hello';
    var_dump($target->length);
    $object->length = '123';
    var_dump($target->length);
    $object->total = [1, 2, 3, 4];
    var_dump($target->total);
    try {
        $object->length = [];
    } catch (TypeError $e) {
        echo "TypeError\n";
    }
}

class RestrictedHooks {
    public int $readOnly { get => 1; }
    public int $writeOnly {
        set {}
    }
    public int $both = 1 {
        get => $this->both;
        set => $this->both = $value;
    }
}
echo "hook direction and unset restrictions\n";
$operations = [
    fn ($o) => $o->readOnly = 2,
    fn ($o) => $o->writeOnly,
    fn ($o) => isset($o->writeOnly),
    function ($o) {
        unset($o->both);
    },
];
$calls = 0;
foreach ([false, true] as $wrapped) {
    $target = new RestrictedHooks;
    $object = $wrapped ? Proxy::wrap($target, propertyInterceptor: function () use (&$calls) {
        $calls++;
        return 42;
    }) : $target;
    foreach ($operations as $operation) {
        try {
            $operation($object);
            echo "unexpected success\n";
        } catch (Error $e) {
            echo "Error\n";
        }
    }
}
var_dump($calls);

class ReadonlyValue {
    public readonly int $value;

    public function __construct() {
        $this->value = 1;
    }

    public static function write(self $object): void {
        $object->value = 2;
    }

    public static function remove(self $object): void {
        unset($object->value);
    }
}
echo "readonly in declaring scope\n";
foreach ([false, true] as $wrapped) {
    $target = new ReadonlyValue;
    $object = $wrapped ? Proxy::wrap($target, propertyInterceptor: function () use (&$calls) {
        $calls++;
        return null;
    }) : $target;
    try {
        ReadonlyValue::write($object);
    } catch (Error $e) {
        echo "write Error\n";
    }
    try {
        ReadonlyValue::remove($object);
    } catch (Error $e) {
        echo "unset Error\n";
    }
    var_dump($target->value);
}
var_dump($calls);

class UninitializedValue {
    public int $number;
}
echo "conditional uninitialized reads\n";
foreach ([false, true] as $wrapped) {
    $target = new UninitializedValue;
    $object = $wrapped ? Proxy::wrap($target, propertyInterceptor: 'forwardProperty') : $target;
    var_dump($object->number ?? 99);
    $object->number ??= 7;
    var_dump($target->number, $object->number ?? 99);
}
$object = Proxy::wrap(new UninitializedValue, propertyInterceptor: fn (PI $call) =>
    $call->property() === 'number' ? null : forwardProperty($call));
try {
    $object->number ?? 99;
} catch (TypeError $e) {
    echo "replacement TypeError\n";
}

class CountedHook {
    public int $reads = 0;
    public int $value {
        get {
            return ++$this->reads;
        }
    }
}
echo "empty reads getter once\n";
foreach ([false, true] as $wrapped) {
    $target = new CountedHook;
    $object = $wrapped ? Proxy::wrap($target, propertyInterceptor: 'forwardProperty') : $target;
    var_dump(empty($object->value), $target->reads);
}
echo "GET middleware still replaces the saved read\n";
$target = new CountedHook;
$ops = [];
$object = Proxy::wrap($target, propertyInterceptor: function (PI $call) use (&$ops) {
    $ops[] = $call->operation()->name;
    return $call->operation() === Op::GET ? 0 : $call->proceed();
});
var_dump(empty($object->value), $target->reads, implode(',', $ops));
echo "repeated proceed performs the requested reads\n";
$target = new CountedHook;
$object = Proxy::wrap($target, propertyInterceptor: function (PI $call) {
    $call->proceed();
    return $call->proceed();
});
var_dump(empty($object->value), $target->reads);
?>
--EXPECT--
widened setters
int(5)
int(3)
int(4)
TypeError
int(5)
int(3)
int(4)
TypeError
hook direction and unset restrictions
Error
Error
Error
Error
Error
Error
Error
Error
int(0)
readonly in declaring scope
write Error
unset Error
int(1)
write Error
unset Error
int(1)
int(0)
conditional uninitialized reads
int(99)
int(7)
int(7)
int(99)
int(7)
int(7)
replacement TypeError
empty reads getter once
bool(false)
int(1)
bool(false)
int(1)
GET middleware still replaces the saved read
bool(true)
int(1)
string(9) "ISSET,GET"
repeated proceed performs the requested reads
bool(false)
int(3)
