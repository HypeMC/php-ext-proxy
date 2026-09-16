--TEST--
Native parity: magic writes to unset slots, error chains, lazy targets, enumeration during mutation, array_walk
--EXTENSIONS--
proxy
--INI--
memory_limit=-1
--FILE--
<?php
use Proxy\Proxy;
use Proxy\PropertyInvocation as PI;
use Proxy\PropertyOperation as Op;

$fwd = fn (PI $c) => $c->operation() === Op::SET ? $c->proceed($c->value()) : $c->proceed();
$fwdRef = function &(PI $c): mixed {
    if ($c->operation() === Op::SET) {
        return $c->proceed($c->value());
    }
    return $c->proceed();
};

function run(callable $fn): string {
    ob_start();
    set_error_handler(function (int $no, string $msg) {
        echo "[$no] $msg\n";
        return true;
    });
    try {
        $r = $fn();
        echo json_encode($r), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage();
        if ($e->getPrevious()) {
            echo ' | previous: ', $e->getPrevious()->getMessage();
        }
        echo "\n";
    } finally {
        restore_error_handler();
    }
    return preg_replace(['/Proxy_\w+/', '/#\d+/'], ['', '#N'], ob_get_clean());
}

/** Runs $case once on a plain object and once on each proxy variant. */
function compare(string $label, callable $make, callable $case, array $interceptors): void {
    $native = run(fn () => $case($make(), false));
    $results = [];
    foreach ($interceptors as $name => $interceptor) {
        $results[$name] = run(fn () => $case(Proxy::wrap($make(), propertyInterceptor: $interceptor), true));
    }
    $diff = array_filter($results, fn ($r) => $r !== $native);
    echo $label, ': ', $diff ? 'DIFFERENT ' . json_encode(['native' => $native] + $diff) : 'same', "\n";
}

$modes = ['forwarder' => $fwd, 'reference forwarder' => $fwdRef];

echo "--- __set() receives the raw value for an explicitly unset typed slot ---\n";
class Magic {
    public int $p;
    public ?int $q;

    public function __construct() {
        unset($this->p, $this->q);
    }

    public function __set($n, $v) {
        echo "[__set $n ", var_export($v, true), "]";
    }
}
foreach (['"5"' => '5', 'string' => 'str', 'float' => 1.5, 'array' => [], 'null' => null] as $label => $value) {
    compare("write $label", fn () => new Magic, function ($o) use ($value) {
        $o->p = $value;
        $o->q = $value;
        return 'ok';
    }, $modes);
}

echo "--- readonly and protected(set) errors chain a failed increment ---\n";
class Ro {
    public readonly stdClass $obj;
    public readonly array $arr;

    public function __construct(public protected(set) array $ps = []) {
        $this->obj = new stdClass;
        $this->arr = [];
    }
}
foreach (['obj', 'arr', 'ps'] as $prop) {
    compare("$prop++", fn () => new Ro, function ($o) use ($prop) {
        $o->$prop++;
        return 'no error';
    }, $modes);
}

echo "--- by-reference assignment to hooked properties ---\n";
class Hooked {
    public int $setonly {
        set {
            $this->setonly = $value;
        }
    }
    public int $virtsetonly {
        set {}
    }
    public int $throwget {
        get {
            throw new RuntimeException('boom');
        }
    }
    public int $initsetonly = 1 {
        set {
            $this->initsetonly = $value;
        }
    }
    public int $byvalget = 1 {
        get {
            return $this->byvalget;
        }
    }
}
foreach (['setonly', 'virtsetonly', 'throwget', 'initsetonly', 'byvalget'] as $prop) {
    compare("$prop = &", fn () => new Hooked, function ($o) use ($prop) {
        $v = 5;
        $o->$prop = &$v;
        return 'no error';
    }, $modes);
}

echo "--- lazy proxy targets: the wrapper's uninitialized slot is inspected first ---\n";
class L {
    public readonly int $ro;

    public function __construct(public protected(set) array $ps = [1]) {
        $this->ro = 1;
    }
}
$lazy = function (bool $initialized) {
    return function () use ($initialized) {
        $o = (new ReflectionClass(L::class))->newLazyProxy(fn () => new L);
        if ($initialized) {
            $o->ps;
        }
        return $o;
    };
};
foreach ([false, true] as $initialized) {
    $state = $initialized ? 'initialized' : 'uninitialized';
    compare("write ro ($state)", $lazy($initialized), function ($o) {
        $o->ro = 2;
        return 'no error';
    }, $modes);
    compare("unset ro ($state)", $lazy($initialized), function ($o) {
        unset($o->ro);
        return 'no error';
    }, $modes);
    compare("ro++ ($state)", $lazy($initialized), function ($o) {
        $o->ro++;
        return 'no error';
    }, $modes);
    compare("unset ps[0] ($state)", $lazy($initialized), function ($o) {
        unset($o->ps[0]);
        return 'no error';
    }, $modes);
}

echo "--- empty() on an explicitly unset declared slot served by magic methods ---\n";
class MagicIsset {
    public $p = 1;

    public function __construct() {
        unset($this->p);
    }

    public function __isset($n) {
        echo "[__isset $n]";
        return true;
    }

    public function __get($n) {
        echo "[__get $n]";
        return 0;
    }
}
compare('empty', fn () => new MagicIsset, fn ($o) => empty($o->p), $modes);

echo "--- target storage stays plain after writable fetches ---\n";
class Falsy {
    public $f = false;
}
compare('nested unset on false', fn () => new Falsy, function ($o) {
    unset($o->f[0][1]);
    unset($o->f[0][1]);
    return $o->f;
}, ['reference forwarder' => $fwdRef]);

echo "--- enumeration while properties change ---\n";
class Shrinking {
    public static ?object $target = null;
    public $a {
        get {
            unset(self::$target->untyped, self::$target->typed);
            return 'a';
        }
    }
    public $untyped = 'u';
    public int $typed = 1;
    public $z = 'z';
}
$makeShrinking = function () {
    return Shrinking::$target = new Shrinking;
};
compare('get_object_vars', $makeShrinking, fn ($o) => get_object_vars($o), ['none' => null]);
compare('json_encode', $makeShrinking, fn ($o) => json_encode($o), ['none' => null]);
compare('var_export', $makeShrinking, fn ($o) => preg_replace('/\\\\\w+::/', 'X::', var_export($o, true)), ['none' => null]);

echo "--- get_object_vars() does not alias single-owner references ---\n";
class Aliased {
    public $x = 1;
    public int $n = 5;
}
compare('write through the result', fn () => new Aliased, function ($o, $proxied) {
    $target = $proxied ? Proxy::target($o) : $o;
    $r = &$target->x;
    unset($r);
    foreach ($o as &$v) {
    }
    unset($v);
    $vars = get_object_vars($o);
    $vars['x'] = 99;
    $vars['n'] = 100;
    return [$target->x, $target->n];
}, ['none' => null]);

echo "--- array_walk continues after the callback removes an earlier property ---\n";
#[AllowDynamicProperties]
class Walked {
    public $a = 1;
}
$makeWalked = function () {
    $o = new Walked;
    foreach (['d1', 'd2', 'd3', 'd4'] as $k) {
        $o->$k = $k;
    }
    return $o;
};
compare('array_walk', $makeWalked, function ($o, $proxied) {
    $target = $proxied ? Proxy::target($o) : $o;
    $keys = [];
    array_walk($o, function ($v, $k) use (&$keys, $target) {
        $keys[] = $k;
        if ($k === 'd2') {
            unset($target->d1);
        }
    });
    return $keys;
}, ['none' => null, 'forwarder' => $fwd]);

echo "--- var_dump() counts uninitialized typed slots like PHP ---\n";
class FirstUninit {
    public int $n;
    public $u = 'u';
}
compare('var_dump', fn () => new FirstUninit, function ($o) {
    var_dump($o);
    return null;
}, ['none' => null]);

echo "--- spreads of classes with property hooks ---\n";
class Spread {
    public $a = 1;
    public $h { get => 'H'; }
}
compare('spread', fn () => new Spread, fn ($o) => [...$o], ['none' => null]);
function gen($o) {
    yield from $o;
}
compare('yield from', fn () => new Spread, fn ($o) => iterator_to_array(gen($o)), ['none' => null]);

echo "--- huge objects allocated by the engine for a generated class ---\n";
// more than 2 MB of property slots: the engine takes a huge allocation
$code = 'class Huge { ' . implode(' ', array_map(fn ($i) => "public \$p$i;", range(1, 140000))) . ' }';
eval($code);
$ghost = (new ReflectionClass(Proxy::wrap(new Huge)))->newLazyGhost(fn ($o) => null);
var_dump(Proxy::isProxy($ghost));
unset($ghost);
echo "done\n";
?>
--EXPECT--
--- __set() receives the raw value for an explicitly unset typed slot ---
write "5": same
write string: same
write float: same
write array: same
write null: same
--- readonly and protected(set) errors chain a failed increment ---
obj++: same
arr++: same
ps++: same
--- by-reference assignment to hooked properties ---
setonly = &: same
virtsetonly = &: same
throwget = &: same
initsetonly = &: same
byvalget = &: same
--- lazy proxy targets: the wrapper's uninitialized slot is inspected first ---
write ro (uninitialized): same
unset ro (uninitialized): same
ro++ (uninitialized): same
unset ps[0] (uninitialized): same
write ro (initialized): same
unset ro (initialized): same
ro++ (initialized): same
unset ps[0] (initialized): same
--- empty() on an explicitly unset declared slot served by magic methods ---
empty: same
--- target storage stays plain after writable fetches ---
nested unset on false: same
--- enumeration while properties change ---
get_object_vars: same
json_encode: same
var_export: same
--- get_object_vars() does not alias single-owner references ---
write through the result: same
--- array_walk continues after the callback removes an earlier property ---
array_walk: same
--- var_dump() counts uninitialized typed slots like PHP ---
var_dump: same
--- spreads of classes with property hooks ---
spread: same
yield from: same
--- huge objects allocated by the engine for a generated class ---
bool(false)
done
