--TEST--
foreach, get_object_vars(), json_encode(), var_export() enumerate through the chain; dumps and casts stay raw
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;
use Proxy\PropertyInvocation as PI;
use Proxy\PropertyOperation as Op;

function keys(object $it): array {
    $r = [];
    foreach ($it as $k => $v) {
        $r[$k] = $v;
    }
    return $r;
}
function same(string $label, mixed $native, mixed $proxied): void {
    echo $label, ': ', $native === $proxied ? 'same' : 'DIFFERENT ' . json_encode([$native, $proxied]), "\n";
}

class Plain {
    public $pub = 1;
    protected $prot = 2;
    private $priv = 3;
    public int $typed = 4;
    public int $uninit;
    public ?int $nul = null;

    public function vars(): array {
        return get_object_vars($this);
    }

    public function scoped(object $other): array {
        return [get_object_vars($other), keys($other), json_encode($other)];
    }
}

echo "--- plain object: same visible keys as PHP ---\n";
$target = new Plain;
$p = Proxy::wrap($target);
same('foreach', keys($target), keys($p));
same('get_object_vars', get_object_vars($target), get_object_vars($p));
same('json_encode', json_encode($target), json_encode($p));
same('(array)', (array) $target, (array) $p);
same('get_object_vars in scope', $target->vars(), $target->scoped($p)[0]);
same('foreach in scope', $target->scoped($target)[1], $target->scoped($p)[1]);
same('json in scope', $target->scoped($target)[2], $target->scoped($p)[2]);
echo preg_replace('/PlainProxy_\d+/', 'PlainProxy', var_export($p, true)), "\n";
ob_start();
var_dump($p);
echo preg_replace('/PlainProxy_\d+\)#\d+/', 'PlainProxy)#N', ob_get_clean());

echo "--- interception applies to enumeration reads ---\n";
class Named {
    public $name = 'real';
    public $other = 'o';
}
$log = [];
$p = Proxy::wrap(new Named, propertyInterceptor: function (PI $pi) use (&$log) {
    $log[] = $pi->operation()->name . ':' . $pi->property();
    return $pi->operation() === Op::GET && $pi->property() === 'name' ? 'INTERCEPTED' : $pi->proceed();
});
var_dump(keys($p) === ['name' => 'INTERCEPTED', 'other' => 'o']);
var_dump(get_object_vars($p) === ['name' => 'INTERCEPTED', 'other' => 'o']);
var_dump(json_encode($p) === '{"name":"INTERCEPTED","other":"o"}');
var_dump(str_contains(var_export($p, true), "'name' => 'INTERCEPTED'"));
echo implode(',', $log), "\n";
$log = [];
$raw = (array) $p;
ob_start();
var_dump($p);
print_r($p);
ob_end_clean();
var_dump($raw['name'], $log);

echo "--- hooks and virtual properties ---\n";
class Hooked {
    public string $h = 'raw' {
        get {
            echo '[hook]';
            return strtoupper($this->h);
        }
    }
    public string $virt { get => 'V'; }
    public int $setOnly {
        set {}
    }
    public $plain = 'p';
}
$target = new Hooked;
$p = Proxy::wrap($target);
same('foreach', keys($target), keys($p));
same('get_object_vars', get_object_vars($target), get_object_vars($p));
same('json_encode', json_encode($target), json_encode($p));
same('(array)', (array) $target, (array) $p);
echo "\n";

echo "--- magic and dynamic properties ---\n";
class Magic {
    private array $d = ['m' => 1];

    public function __get($n) {
        echo "[__get $n]";
        return $this->d[$n] ?? null;
    }

    public function __isset($n) {
        echo "[__isset $n]";
        return isset($this->d[$n]);
    }
}
$target = new Magic;
$p = Proxy::wrap($target);
same('foreach', keys($target), keys($p));
same('get_object_vars', get_object_vars($target), get_object_vars($p));
same('(array)', (array) $target, (array) $p);
#[AllowDynamicProperties]
class Dyn {
    public $a = 1;
}
$target = new Dyn;
$target->dyn = 'D';
$target->{'0'} = 'zero';
$p = Proxy::wrap($target);
same('dynamic foreach', keys($target), keys($p));
same('dynamic json', json_encode($target), json_encode($p));
echo "added during iteration: ";
$target = new Dyn;
$target->d1 = 1;
$p = Proxy::wrap($target);
foreach ($p as $k => $v) {
    echo "$k;";
    if ($k === 'd1') {
        $target->d2 = 2;
    }
}
echo "\n";

echo "--- foreach by reference writes through, with the property type ---\n";
$target = new Plain;
$p = Proxy::wrap($target, propertyInterceptor: fn &(PI $pi) => $pi->proceed());
foreach ($p as $k => &$v) {
    if (is_int($v)) {
        $v *= 10;
    }
}
unset($v);
var_dump($target->pub, $target->typed);
try {
    foreach ($p as $k => &$v) {
        if ($k === 'typed') {
            $v = 'str';
        }
    }
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
unset($v);
try {
    foreach (Proxy::wrap(new Hooked) as &$v) {
    }
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
unset($v);
// array_walk() writes through to the target storage.
$target = new Plain;
$p = Proxy::wrap($target);
array_walk($p, function (&$v) {
    if (is_int($v)) {
        $v++;
    }
});
var_dump($target->pub);

echo "--- mocks enumerate declared properties through the chain ---\n";
class K {
    public string $a = 'x';
    public int $b = 1;
    protected $c = 3;
}
$m = Proxy::mock(K::class, propertyInterceptor: fn (PI $pi) => match ($pi->property()) {
    'a' => 'A', 'b' => 2, default => $pi->proceed()
});
var_dump(keys($m), json_encode($m), get_object_vars($m));
ob_start();
var_dump($m);
echo preg_replace('/KProxy_\d+\)#\d+/', 'KProxy)#N', ob_get_clean());
try {
    foreach (Proxy::mock(K::class) as $v) {
    }
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo "--- recursion and lazy targets ---\n";
#[AllowDynamicProperties]
class Node {
}
$node = new Node;
$p = Proxy::wrap($node);
$node->self = $p;
var_dump(json_encode($p), json_last_error_msg());
unset($node->self);
class Bag {
    public array $items = [];
}
$p = Proxy::wrap((new ReflectionClass(Bag::class))->newLazyGhost(function ($o) {
    unset($GLOBALS['p']);
    $o->items = [1];
}));
foreach ($p as $k => $v) {
    echo "$k=", json_encode($v), "\n";
}
class Agg implements IteratorAggregate {
    public function getIterator(): Iterator {
        return new ArrayIterator([1]);
    }
}
$p = Proxy::wrap(new Agg, methodInterceptor: function ($c) {
    unset($GLOBALS['p']);
    return null;
});
try {
    foreach ($p as $v) {
    }
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo "--- internal consumers of the properties table see declared and dynamic entries like PHP ---\n";
#[AllowDynamicProperties]
class Cons {
    public $a = 1;
    protected $b = 2;
    private $c = 3;
    public int $u;
}
$target = new Cons;
$target->dyn = 'd';
$p = Proxy::wrap($target);
var_dump(http_build_query($p) === http_build_query($target));
var_dump(array_keys(get_mangled_object_vars($p)) === array_keys(get_mangled_object_vars($target)));
$walked = [];
array_walk($p, function ($v, $k) use (&$walked) {
    $walked[] = $k;
});
$native = [];
array_walk($target, function ($v, $k) use (&$native) {
    $native[] = $k;
});
var_dump($walked === $native);
$names = array_map(fn ($r) => $r->getName() . ($r->isDefault() ? '' : '(dyn)'), (new ReflectionObject($p))->getProperties());
var_dump($names);

echo "--- inherited properties are read once, consumers apply their guards first ---\n";
class HBase {
    public string $h = 'x' {
        get {
            echo '[hook]';
            return $this->h;
        }
    }
    public $plain = 1;
}
class HChild extends HBase {
}
$reads = 0;
$p = Proxy::wrap(new HChild, propertyInterceptor: function (PI $pi) use (&$reads) {
    $reads++;
    return $pi->proceed();
});
var_dump(json_encode($p), $reads);
$reads = 0;
var_export($p);
echo "\n";
var_dump($reads);
class Throwing {
    public $a = 1;
    public int $b {
        get {
            throw new RuntimeException('boom');
        }
    }
}
ob_start();
try {
    var_export(Proxy::wrap(new Throwing));
    echo "not reached";
} catch (RuntimeException $e) {
    $partial = ob_get_clean();
    echo "exception, partial output: ", var_export($partial, true), "\n";
}
$P = null;
class Reenter {
    public $plain = 'p';
    public $h = 'h' {
        get {
            global $P;
            static $depth = 0;
            if ($P && $depth++ === 0) {
                $r = json_encode($P);
                echo '[inner:', var_export($r, true), ']';
                $depth--;
            }
            return strtoupper($this->h);
        }
    }
}
$P = Proxy::wrap(new Reenter, propertyInterceptor: fn &(PI $pi) => $pi->proceed());
var_dump(json_encode($P));

echo "--- only foreach iterates a plain proxy ---\n";
$p = Proxy::wrap(new Plain);
function gen($o) {
    yield from $o;
}
try {
    foreach (gen($p) as $v) {
    }
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_dump([...$p]);
} catch (TypeError $e) {
    echo get_class($e), ': ', preg_replace('/Proxy_\d+/', 'Proxy', $e->getMessage()), "\n";
}
class MyIterator extends ArrayIterator {
}
$p = Proxy::wrap(new MyIterator([1, 2]));
foreach ($p as $k => &$v) {
    $v *= 10;
}
unset($v);
var_dump(iterator_to_array($p));
class HookedStorage extends ArrayObject {
    public $v { get => 'virt'; }
 public $b = 1;
}
var_dump(get_object_vars(Proxy::wrap(new HookedStorage([1]))));

echo "--- large dynamic tables that grow during iteration ---\n";
$target = new Dyn;
for ($i = 1; $i < 70000; $i++) {
    $target->{"d$i"} = $i;
}
$p = Proxy::wrap($target, propertyInterceptor: function (PI $pi) use ($target) {
    if ($pi->operation() === Op::GET && $pi->property() === 'd1') {
        $target->grow = 1;
    }
    return $pi->proceed();
});
$n = 0;
foreach ($p as $k => $v) {
    $n++;
}
var_dump($n === 70001);
echo "done\n";
?>
--EXPECTF--
--- plain object: same visible keys as PHP ---
foreach: same
get_object_vars: same
json_encode: same
(array): same
get_object_vars in scope: same
foreach in scope: same
json in scope: same
\PlainProxy::__set_state(array(
   'pub' => 1,
   'prot' => 2,
   'priv' => 3,
   'typed' => 4,
   'nul' => NULL,
))
object(PlainProxy)#N (5) {
  ["pub"]=>
  int(1)
  ["prot":protected]=>
  int(2)
  ["priv":"Plain":private]=>
  int(3)
  ["typed"]=>
  int(4)
  ["uninit"]=>
  uninitialized(int)
  ["nul"]=>
  NULL
}
--- interception applies to enumeration reads ---
bool(true)
bool(true)
bool(true)
bool(true)
GET:name,GET:other,GET:name,GET:other,GET:name,GET:other,GET:name,GET:other
string(4) "real"
array(0) {
}
--- hooks and virtual properties ---
[hook][hook]foreach: same
[hook][hook]get_object_vars: same
[hook][hook]json_encode: same
(array): same

--- magic and dynamic properties ---
foreach: same
get_object_vars: same
(array): same
dynamic foreach: same
dynamic json: same
added during iteration: a;d1;d2;
--- foreach by reference writes through, with the property type ---
int(10)
int(40)
Cannot assign string to reference held by property Plain::$typed of type int
Cannot create reference to property Hooked::$h
int(2)
--- mocks enumerate declared properties through the chain ---
array(2) {
  ["a"]=>
  string(1) "A"
  ["b"]=>
  int(2)
}
string(15) "{"a":"A","b":2}"
array(2) {
  ["a"]=>
  string(1) "A"
  ["b"]=>
  int(2)
}
object(KProxy)#N (0) {
  ["a"]=>
  uninitialized(string)
  ["b"]=>
  uninitialized(int)
}
Proxy\Exception\UnconfiguredProperty: Unconfigured GET of property K::$a on mock
--- recursion and lazy targets ---
bool(false)
string(18) "Recursion detected"
items=[1]
TypeError: Agg::getIterator(): Return value must be of type Iterator, null returned
--- internal consumers of the properties table see declared and dynamic entries like PHP ---
bool(true)
bool(true)
bool(true)
array(4) {
  [0]=>
  string(1) "a"
  [1]=>
  string(1) "b"
  [2]=>
  string(1) "u"
  [3]=>
  string(8) "dyn(dyn)"
}
--- inherited properties are read once, consumers apply their guards first ---
[hook]string(19) "{"h":"x","plain":1}"
int(2)
[hook]\HChildProxy_%s::__set_state(array(
   'h' => 'x',
   'plain' => 1,
))
int(2)
exception, partial output: ''
[inner:false]string(21) "{"plain":"p","h":"H"}"
--- only foreach iterates a plain proxy ---
Error: Can use "yield from" only with arrays and Traversables
TypeError: Only arrays and Traversables can be unpacked, PlainProxy given
array(2) {
  [0]=>
  int(10)
  [1]=>
  int(20)
}
array(2) {
  ["v"]=>
  string(4) "virt"
  ["b"]=>
  int(1)
}
--- large dynamic tables that grow during iteration ---
bool(true)
done
