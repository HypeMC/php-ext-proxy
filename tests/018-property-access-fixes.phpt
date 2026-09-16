--TEST--
Property operations keep PHP's semantics through interceptors: references, unset, readonly, hooks, empty(), errors
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;
use Proxy\PropertyInvocation as PI;
use Proxy\PropertyOperation as Op;

function attempt(string $label, callable $operation): void {
    echo $label, ': ';
    try {
        $result = $operation();
        echo is_string($result) ? $result : json_encode($result), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
$forwardByReference = function &(PI $invocation): mixed {
    if ($invocation->operation() === Op::SET) {
        return $invocation->proceed($invocation->value());
    }
    return $invocation->proceed();
};
$forwardByValue = fn (PI $invocation) => $invocation->operation() === Op::SET
    ? $invocation->proceed($invocation->value()) : $invocation->proceed();

echo "--- by-reference forwarders hand out the target's storage ---\n";
class S {
    public int $five = 5;
    public $untyped = 1;
    public ?int $ni = 2;
    public ?int $nn = null;
    public int $i;
    public stdClass $obj;
    public array $arr;
}
attempt('five = &v', function () use ($forwardByReference) {
    $target = new S;
    $proxy = Proxy::wrap($target, propertyInterceptor: $forwardByReference);
    $v = 7;
    $proxy->five = &$v;
    $v = 8;
    return "p={$proxy->five} t={$target->five}";
});
attempt('untyped = &v', function () use ($forwardByReference) {
    $target = new S;
    $proxy = Proxy::wrap($target, propertyInterceptor: $forwardByReference);
    $v = 7;
    $proxy->untyped = &$v;
    $v = 8;
    return $target->untyped;
});
attempt('uninitialized i = &v', function () use ($forwardByReference) {
    $target = new S;
    $proxy = Proxy::wrap($target, propertyInterceptor: $forwardByReference);
    $v = 3;
    $proxy->i = &$v;
    $v = 4;
    return $target->i;
});
attempt('typed reference rejects string', function () use ($forwardByReference) {
    $target = new S;
    $proxy = Proxy::wrap($target, propertyInterceptor: $forwardByReference);
    $v = 7;
    $proxy->five = &$v;
    $v = 'x';
    return 'no error';
});
attempt('nested write on uninitialized object', function () use ($forwardByReference) {
    $proxy = Proxy::wrap(new S, propertyInterceptor: $forwardByReference);
    $proxy->obj->y = 1;
    return 'no error';
});
attempt('append to uninitialized array', function () use ($forwardByReference) {
    $target = new S;
    $proxy = Proxy::wrap($target, propertyInterceptor: $forwardByReference);
    $proxy->arr[] = 1;
    return $target->arr;
});
attempt('append to nullable int', function () use ($forwardByReference) {
    $proxy = Proxy::wrap(new S, propertyInterceptor: $forwardByReference);
    $proxy->nn[] = 1;
    return 'no error';
});

echo "--- unset() of an offset on an uninitialized typed property is a no-op ---\n";
attempt('unset(i[k]) by reference', function () use ($forwardByReference) {
    $target = new S;
    $proxy = Proxy::wrap($target, propertyInterceptor: $forwardByReference);
    unset($proxy->i['k']);
    return isset($target->i) ? 'initialized' : 'still uninitialized';
});
attempt('unset(arr[k]) by reference', function () use ($forwardByReference) {
    $target = new S;
    $proxy = Proxy::wrap($target, propertyInterceptor: $forwardByReference);
    unset($proxy->arr['k']);
    return isset($target->arr) ? 'initialized' : 'still uninitialized';
});
attempt('unset(ni[k]) on int', function () use ($forwardByReference) {
    $proxy = Proxy::wrap(new S, propertyInterceptor: $forwardByReference);
    unset($proxy->ni['k']);
    return 'no error';
});

echo "--- overloaded-property notice is raised once ---\n";
class Magic {
    private $d = [];

    public function __get($n) {
        return $this->d[$n] ?? null;
    }

    public function __set($n, $v) {
        $this->d[$n] = $v;
    }
}
set_error_handler(function (int $no, string $msg) {
    echo "notice: $msg\n";
    return true;
});
$proxy = Proxy::wrap(new Magic, propertyInterceptor: $forwardByValue);
$proxy->arr[] = 1;
class Plain {
    public array $list = [];
}
$proxy = Proxy::wrap(new Plain, propertyInterceptor: fn (PI $invocation) =>
    $invocation->operation() === Op::GET ? ['replacement'] : $invocation->proceed($invocation->value()));
$proxy->list[] = 1;
restore_error_handler();

echo "--- increment and decrement past the integer range ---\n";
class Counter {
    public int $n = 0;
}
attempt('decrement at PHP_INT_MIN', function () use ($forwardByValue) {
    $target = new Counter;
    $proxy = Proxy::wrap($target, propertyInterceptor: $forwardByValue);
    $proxy->n = PHP_INT_MIN;
    $proxy->n--;
    return $target->n;
});
attempt('increment at PHP_INT_MAX', function () use ($forwardByValue) {
    $target = new Counter;
    $proxy = Proxy::wrap($target, propertyInterceptor: $forwardByValue);
    $proxy->n = PHP_INT_MAX;
    ++$proxy->n;
    return $target->n;
});
attempt('ordinary increment', function () use ($forwardByValue) {
    $target = new Counter;
    $proxy = Proxy::wrap($target, propertyInterceptor: $forwardByValue);
    $proxy->n++;
    ++$proxy->n;
    return $target->n;
});

echo "--- readonly checks inspect the real storage of lazy targets ---\n";
class R {
    public readonly int $v;

    public function __construct() {
        $this->v = 1;
    }

    public static function write(object $o, int $val): void {
        $o->v = $val;
    }
}
$swallow = fn (PI $invocation) => null;
attempt('initialized lazy proxy', function () use ($swallow) {
    $lazy = (new ReflectionClass(R::class))->newLazyProxy(fn () => new R);
    $lazy->v;
    R::write(Proxy::wrap($lazy, propertyInterceptor: $swallow), 2);
    return 'NO ERROR';
});
attempt('initialized lazy ghost', function () use ($swallow) {
    $lazy = (new ReflectionClass(R::class))->newLazyGhost(fn ($o) => $o->__construct());
    $lazy->v;
    R::write(Proxy::wrap($lazy, propertyInterceptor: $swallow), 2);
    return 'NO ERROR';
});
attempt('uninitialized lazy ghost initializes first', function () use ($swallow) {
    $lazy = (new ReflectionClass(R::class))->newLazyGhost(function ($o) {
        echo '[init]';
        $o->__construct();
    });
    R::write(Proxy::wrap($lazy, propertyInterceptor: $swallow), 2);
    return 'NO ERROR';
});

echo "--- explicitly unset readonly slot dispatches to __set()/__unset() ---\n";
class LazyValue {
    public readonly int $value;

    public function __construct() {
        unset($this->value);
    }

    public function __set($n, $v) {
        echo "[__set $n]";
        $this->value = $v;
    }

    public function __unset($n) {
        echo "[__unset $n]";
    }
}
attempt('write', function () use ($forwardByValue) {
    $target = new LazyValue;
    $proxy = Proxy::wrap($target, propertyInterceptor: $forwardByValue);
    $proxy->value = 7;
    return $target->value;
});
attempt('unset', function () use ($forwardByValue) {
    $proxy = Proxy::wrap(new LazyValue, propertyInterceptor: $forwardByValue);
    unset($proxy->value);
    return 'ok';
});

echo "--- asymmetric visibility errors name the caller's scope ---\n";
class AV {
    public function __construct(public protected(set) int $x = 1, public private(set) array $arr = []) {
    }
}
attempt('reference from global scope', function () use ($forwardByValue) {
    $proxy = Proxy::wrap(new AV, propertyInterceptor: $forwardByValue);
    $r = &$proxy->x;
    return 'no error';
});
attempt('append from global scope', function () use ($forwardByValue) {
    $proxy = Proxy::wrap(new AV, propertyInterceptor: $forwardByValue);
    $proxy->arr[] = 1;
    return 'no error';
});
attempt('write from global scope', function () use ($forwardByValue) {
    $proxy = Proxy::wrap(new AV, propertyInterceptor: $forwardByValue);
    $proxy->x = 2;
    return 'no error';
});

echo "--- set hook input violations use the hook's argument error ---\n";
class H {
    public int $wide = 0 {
        set(int|string $v) {
            $this->wide = (int) $v;
        }
    }
    public int $implicit = 0 {
        set {
            $this->implicit = $value * 2;
        }
    }
    public ?H $next = null {
        set(?self $v) {
            $this->next = $v;
        }
    }
}
attempt('array for int|string', function () use ($forwardByValue) {
    $proxy = Proxy::wrap(new H, propertyInterceptor: $forwardByValue);
    $proxy->wide = [];
    return 'no error';
});
attempt('string for implicit int', function () use ($forwardByValue) {
    $proxy = Proxy::wrap(new H, propertyInterceptor: $forwardByValue);
    $proxy->implicit = 'abc';
    return 'no error';
});
attempt('stdClass for ?self', function () use ($forwardByValue) {
    $proxy = Proxy::wrap(new H, propertyInterceptor: $forwardByValue);
    $proxy->next = new stdClass;
    return 'no error';
});
attempt('coerced numeric string', function () use ($forwardByValue) {
    $target = new H;
    $proxy = Proxy::wrap($target, propertyInterceptor: $forwardByValue);
    $proxy->wide = '12';
    return $target->wide;
});

echo "--- conditional reads re-validate a property initialized by middleware ---\n";
class U {
    public int $n;
}
attempt('initialized after proceed', function () {
    $target = new U;
    $proxy = Proxy::wrap($target, propertyInterceptor: function (PI $invocation) use ($target) {
        $invocation->proceed();
        $target->n = 5;
        return null;
    });
    return $proxy->n ?? 'default';
});
attempt('still uninitialized', function () {
    $proxy = Proxy::wrap(new U, propertyInterceptor: function (PI $invocation) {
        $invocation->proceed();
        return null;
    });
    return $proxy->n ?? 'default';
});

echo "--- named variadic argument errors report the real position ---\n";
class C {
    public function vari(int ...$n) {
    }

    public function mixed(string $a, int ...$n) {
    }
}
attempt('vari(1, 2, 3, x: str)', function () {
    Proxy::wrap(new C)->vari(1, 2, 3, x: 'str');
    return 'no error';
});
attempt('mixed(a, 1, y: str)', function () {
    Proxy::wrap(new C)->mixed('a', 1, y: 'str');
    return 'no error';
});

echo "--- empty() evaluates __isset()/getters once ---\n";
class Mg {
    public $isset = 0;
    public $get = 0;

    public function __get($n) {
        $this->get++;
        return 'v';
    }

    public function __isset($n) {
        $this->isset++;
        return true;
    }
}
attempt('magic', function () use ($forwardByValue) {
    $target = new Mg;
    $proxy = Proxy::wrap($target, propertyInterceptor: $forwardByValue);
    $e = empty($proxy->x);
    return "empty=" . var_export($e, true) . " isset={$target->isset} get={$target->get}";
});
class AH extends ArrayObject {
    public int $calls = 0;
    public string $hooked {
        get {
            $this->calls++;
            return 'x';
        }
    }
}
attempt('hooked with internal ancestry', function () use ($forwardByValue) {
    $target = new AH;
    $proxy = Proxy::wrap($target, propertyInterceptor: $forwardByValue);
    $e = empty($proxy->hooked);
    return "empty=" . var_export($e, true) . " calls={$target->calls}";
});

echo "--- ReflectionProperty raw access bypasses hooks and interceptors ---\n";
class F9 {
    public string $h = 'raw' {
        get => strtoupper($this->h);
        set {
            echo '[set-hook]';
            $this->h = $value;
        }
    }
}
$reads = 0;
$proxy = Proxy::wrap($target = new F9, propertyInterceptor: function (PI $invocation) use (&$reads) {
    $reads++;
    return $invocation->proceed(...($invocation->operation() === Op::SET ? [$invocation->value()] : []));
});
$prop = new ReflectionProperty(F9::class, 'h');
var_dump($prop->getRawValue($proxy), $prop->getValue($proxy), $reads);
$prop->setRawValue($proxy, 'x');
var_dump($target->h);
$prop->setValue($proxy, 'y');
var_dump($target->h, $reads);

echo "--- PropertyInvocation::proceed() argument count ---\n";
class N {
    public int $n = 1;
}
$proxy = Proxy::wrap(new N, propertyInterceptor: fn (PI $invocation) =>
    $invocation->operation() === Op::SET ? $invocation->proceed() : $invocation->proceed(5));
attempt('GET with an argument', fn () => $proxy->n);
attempt('SET without the value', function () use ($proxy) {
    $proxy->n = 3;
    return 'no error';
});
attempt('ISSET with an argument', fn () => isset($proxy->n));
$proxy = Proxy::wrap(new N, propertyInterceptor: fn (PI $invocation) => $invocation->proceed(1, 2));
attempt('SET with two values', function () use ($proxy) {
    $proxy->n = 3;
    return 'no error';
});
$proxy = Proxy::wrap(new N, propertyInterceptor: fn (PI $invocation) => $invocation->proceed(value: 1));
attempt('named argument', function () use ($proxy) {
    $proxy->n = 3;
    return 'no error';
});

echo "--- readonly indirect modification is refused before any interceptor runs ---\n";
class RO {
    public readonly int $v;
    public readonly array $arr;

    public function __construct() {
        $this->v = 1;
        $this->arr = [1];
    }
}
$swallow = fn (PI $invocation) => $invocation->operation() === Op::GET ? ($invocation->property() === 'v' ? 5 : [1]) : null;
set_error_handler(function (int $no, string $msg) {
    echo "notice: $msg\n";
    return true;
});
attempt('reference', function () use ($swallow) {
    $proxy = Proxy::wrap(new RO, propertyInterceptor: $swallow);
    $r = &$proxy->v;
    return 'no error';
});
attempt('append', function () use ($swallow) {
    $proxy = Proxy::wrap(new RO, propertyInterceptor: $swallow);
    $proxy->arr[] = 2;
    return 'no error';
});
attempt('unset offset', function () use ($swallow) {
    $proxy = Proxy::wrap(new RO, propertyInterceptor: $swallow);
    unset($proxy->arr[0]);
    return 'no error';
});
attempt('mock reference', function () use ($swallow) {
    $m = Proxy::mock(RO::class, propertyInterceptor: $swallow);
    $r = &$m->v;
    return 'no error';
});
restore_error_handler();

echo "--- unset() of an offset on uninitialized readonly and asymmetric arrays ---\n";
class UA {
    public readonly array $ro;
    public protected(set) array $ps;
    public private(set) array $pv;
}
foreach (['ro', 'ps', 'pv'] as $prop) {
    attempt("unset({$prop}[0])", function () use ($forwardByReference, $prop) {
        $target = new UA;
        $proxy = Proxy::wrap($target, propertyInterceptor: $forwardByReference);
        unset($proxy->$prop[0]);
        return isset($target->$prop) ? 'initialized' : 'still uninitialized';
    });
}
attempt('released proxy during unset of an uninitialized int', function () {
    $target = new S;
    $proxy = Proxy::wrap($target, propertyInterceptor: function &(PI $invocation) {
        unset($GLOBALS['p']);
        return $invocation->proceed();
    });
    $GLOBALS['p'] = $proxy;
    unset($proxy);
    unset($GLOBALS['p']->i[0]);
    return [isset($target->i), (new ReflectionProperty($target, 'i'))->isInitialized($target)];
});

echo "--- uninitialized lazy targets: PHP's visibility check comes before initialization ---\n";
class LAV {
    public function __construct(public protected(set) int $x = 1) {
    }
}
class LRO {
    public readonly int $value;

    public function __construct() {
        unset($this->value);
    }

    public function __set($n, $v) {
        echo "[__set $n]";
    }

    public function __unset($n) {
        echo "[__unset $n]";
    }
}
$rc = fn (string $class) => new ReflectionClass($class);
attempt('protected(set) write', function () use ($forwardByValue, $rc) {
    $proxy = Proxy::wrap($rc(LAV::class)->newLazyGhost(function ($o) {
        echo '[init]';
        $o->__construct();
    }), propertyInterceptor: $forwardByValue);
    $proxy->x = 2;
    return 'no error';
});
attempt('readonly write through ghost', function () use ($forwardByValue, $rc) {
    $proxy = Proxy::wrap($rc(LRO::class)->newLazyGhost(function ($o) {
        echo '[init]';
        $o->__construct();
    }), propertyInterceptor: $forwardByValue);
    $proxy->value = 3;
    return 'no error';
});
attempt('readonly unset through lazy proxy', function () use ($forwardByValue, $rc) {
    $proxy = Proxy::wrap($rc(LRO::class)->newLazyProxy(function () {
        echo '[init]';
        return new LRO;
    }), propertyInterceptor: $forwardByValue);
    unset($proxy->value);
    return 'no error';
});
attempt('initialized ghost dispatches to __unset', function () use ($forwardByValue, $rc) {
    $g = $rc(LRO::class)->newLazyGhost(fn ($o) => $o->__construct());
    $g->value ?? null;
    $proxy = Proxy::wrap($g, propertyInterceptor: $forwardByValue);
    unset($proxy->value);
    return 'ok';
});

echo "--- increment past the integer range only for integer slots ---\n";
class Inc {
    public int|string $numeric = '9223372036854775807';
    public int $hooked = 0 {
        set(int|string $v) {
            $this->hooked = (int) $v;
        }
    }
    public int $implicit = 0 {
        set {
            $this->implicit = $value;
        }
    }
    public readonly int $ro;
    public protected(set) int $ps = PHP_INT_MAX;
    public int|string $mixed = PHP_INT_MAX;

    public function __construct() {
        $this->ro = PHP_INT_MAX;
        $this->hooked = PHP_INT_MAX;
        $this->implicit = PHP_INT_MAX;
    }
}
foreach (['numeric', 'hooked', 'implicit', 'ro', 'ps', 'mixed'] as $prop) {
    attempt("$prop++", function () use ($forwardByValue, $prop) {
        $target = new Inc;
        $proxy = Proxy::wrap($target, propertyInterceptor: $forwardByValue);
        $proxy->$prop++;
        return var_export($target->$prop, true);
    });
}

echo "--- empty() with __isset() but no __get() ---\n";
class IssetOnly {
    public $calls = 0;

    public function __isset($n) {
        $this->calls++;
        return true;
    }
}
attempt('empty', function () use ($forwardByValue) {
    $target = new IssetOnly;
    $proxy = Proxy::wrap($target, propertyInterceptor: $forwardByValue);
    $e = empty($proxy->x);
    return [$e, $target->calls];
});

echo "--- by-reference assignment to a hooked property ---\n";
class HookedArr {
    public array $bv = [1] {
        get {
            return $this->bv;
        }
    }
}
attempt('hooked = &', function () use ($forwardByValue) {
    $proxy = Proxy::wrap(new HookedArr, propertyInterceptor: $forwardByValue);
    $v = [7];
    try {
        $proxy->bv = &$v;
    } catch (Error $e) {
        return $e->getMessage() . ' | previous: ' . $e->getPrevious()?->getMessage();
    }
    return 'no error';
});
echo "done\n";
?>
--EXPECTF--
--- by-reference forwarders hand out the target's storage ---
five = &v: p=8 t=8
untyped = &v: 8
uninitialized i = &v: 4
typed reference rejects string: TypeError: Cannot assign string to reference held by property S::$five of type int
nested write on uninitialized object: Error: Attempt to assign property "y" on null
append to uninitialized array: [1]
append to nullable int: TypeError: Cannot auto-initialize an array inside property S::$nn of type ?int
--- unset() of an offset on an uninitialized typed property is a no-op ---
unset(i[k]) by reference: still uninitialized
unset(arr[k]) by reference: still uninitialized
unset(ni[k]) on int: Error: Cannot unset offset in a non-array variable
--- overloaded-property notice is raised once ---
notice: Indirect modification of overloaded property Magic::$arr has no effect
notice: Indirect modification of overloaded property PlainProxy_%s::$list has no effect
--- increment and decrement past the integer range ---
decrement at PHP_INT_MIN: TypeError: Cannot decrement property Counter::$n of type int past its minimal value
increment at PHP_INT_MAX: TypeError: Cannot increment property Counter::$n of type int past its maximal value
ordinary increment: 2
--- readonly checks inspect the real storage of lazy targets ---
initialized lazy proxy: Error: Cannot modify readonly property R::$v
initialized lazy ghost: Error: Cannot modify readonly property R::$v
uninitialized lazy ghost initializes first: [init]Error: Cannot modify readonly property R::$v
--- explicitly unset readonly slot dispatches to __set()/__unset() ---
write: [__set value]7
unset: [__unset value]ok
--- asymmetric visibility errors name the caller's scope ---
reference from global scope: Error: Cannot indirectly modify protected(set) property AV::$x from global scope
append from global scope: Error: Cannot indirectly modify private(set) property AV::$arr from global scope
write from global scope: Error: Cannot modify protected(set) property AV::$x from global scope
--- set hook input violations use the hook's argument error ---
array for int|string: TypeError: H::$wide::set(): Argument #1 ($v) must be of type string|int, array given, called in %s on line %d
string for implicit int: TypeError: H::$implicit::set(): Argument #1 ($value) must be of type int, string given, called in %s on line %d
stdClass for ?self: TypeError: H::$next::set(): Argument #1 ($v) must be of type ?H, stdClass given, called in %s on line %d
coerced numeric string: 12
--- conditional reads re-validate a property initialized by middleware ---
initialized after proceed: TypeError: Cannot assign null to property U::$n of type int
still uninitialized: default
--- named variadic argument errors report the real position ---
vari(1, 2, 3, x: str): TypeError: C::vari(): Argument #4 must be of type int, string given, called in %s on line %d
mixed(a, 1, y: str): TypeError: C::mixed(): Argument #3 must be of type int, string given, called in %s on line %d
--- empty() evaluates __isset()/getters once ---
magic: empty=false isset=1 get=1
hooked with internal ancestry: empty=false calls=1
--- ReflectionProperty raw access bypasses hooks and interceptors ---
string(3) "raw"
string(3) "RAW"
int(1)
string(1) "X"
[set-hook]string(1) "Y"
int(2)
--- PropertyInvocation::proceed() argument count ---
GET with an argument: ArgumentCountError: Proxy\PropertyInvocation::proceed() expects exactly 0 arguments for GET operations, 1 given
SET without the value: ArgumentCountError: Proxy\PropertyInvocation::proceed() expects exactly 1 argument for SET operations, 0 given
ISSET with an argument: ArgumentCountError: Proxy\PropertyInvocation::proceed() expects exactly 0 arguments for ISSET operations, 1 given
SET with two values: ArgumentCountError: Proxy\PropertyInvocation::proceed() expects exactly 1 argument for SET operations, 2 given
named argument: ArgumentCountError: Proxy\PropertyInvocation::proceed() does not accept named arguments
--- readonly indirect modification is refused before any interceptor runs ---
reference: Error: Cannot indirectly modify readonly property RO::$v
append: Error: Cannot indirectly modify readonly property RO::$arr
unset offset: Error: Cannot indirectly modify readonly property RO::$arr
mock reference: Error: Cannot indirectly modify readonly property RO::$v
--- unset() of an offset on uninitialized readonly and asymmetric arrays ---
unset(ro[0]): still uninitialized
unset(ps[0]): still uninitialized
unset(pv[0]): still uninitialized
released proxy during unset of an uninitialized int: [false,false]
--- uninitialized lazy targets: PHP's visibility check comes before initialization ---
protected(set) write: Error: Cannot modify protected(set) property LAV::$x from global scope
readonly write through ghost: Error: Cannot modify protected(set) readonly property LRO::$value from global scope
readonly unset through lazy proxy: Error: Cannot unset protected(set) readonly property LRO::$value from global scope
initialized ghost dispatches to __unset: [__unset value]ok
--- increment past the integer range only for integer slots ---
numeric++: '9.2233720368548E+18'
hooked++: 9223372036854775807
implicit++: TypeError: Inc::$implicit::set(): Argument #1 ($value) must be of type int, float given, called in %s on line %d
ro++: Error: Cannot modify readonly property Inc::$ro
ps++: Error: Cannot modify protected(set) property Inc::$ps from global scope
mixed++: TypeError: Cannot increment property Inc::$mixed of type string|int past its maximal value
--- empty() with __isset() but no __get() ---
empty: [true,1]
--- by-reference assignment to a hooked property ---
hooked = &: Cannot assign by reference to overloaded object | previous: Indirect modification of HookedArr::$bv is not allowed
done
