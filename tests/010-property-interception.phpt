--TEST--
Property interception: GET/SET/ISSET/UNSET routing, visibility, readonly, asymmetric visibility, hooks
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;
use Proxy\PropertyInvocation as PI;
use Proxy\PropertyOperation as Op;

class User {
    public string $name = 'real';
    public array $tags = ['a'];
    public int $count = 0;
    protected string $secret = 's';
    private string $hidden = 'h';
    public readonly int $id;
    public protected(set) string $role = 'user';
    public ?User $friend = null;

    public function __construct() {
        $this->id = 7;
    }

    public function readHidden(): string {
        return $this->hidden;
    }

    public function setRole(string $r): void {
        $this->role = $r;
    }
}

$u = new User();
$log = [];
$p = Proxy::wrap($u,
    propertyInterceptor: function &(PI $prop) use (&$log): mixed {
        $log[] = $prop->operation()->name . ':' . $prop->property();
        if ($prop->property() === 'name') {
            $result = match ($prop->operation()) {
                Op::GET => 'Fake ' . $prop->proceed(),
                Op::SET => $prop->proceed(strtoupper($prop->value())),
                Op::ISSET => true,
                Op::UNSET => null,
            };
            return $result;
        }
        if ($prop->operation() === Op::SET) {
            return $prop->proceed($prop->value());
        }
        return $prop->proceed();
    },
);

echo "--- GET/SET name ---\n";
var_dump($p->name);
$p->name = 'bob';
var_dump($u->name, $p->name);
echo "--- untouched prop delegates ---\n";
var_dump($p->count);
$p->count = 5;
var_dump($u->count);
$p->count++;
var_dump($u->count);
$p->tags[] = 'b';
var_dump($u->tags);
echo "--- isset/empty/unset ---\n";
var_dump(isset($p->name), isset($p->friend), empty($p->count), isset($p->nope));
unset($p->friend);
try {
    var_dump($p->friend);
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
echo "--- visibility preserved ---\n";
try {
    $p->secret;
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
try {
    $p->hidden = 'x';
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
var_dump(isset($p->secret));
echo "--- readonly preserved ---\n";
var_dump($p->id);
try {
    $p->id = 9;
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
echo "--- asymmetric visibility ---\n";
var_dump($p->role);
try {
    $p->role = 'admin';
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
$p->setRole('admin');
var_dump($u->role, $p->role);
echo "--- dynamic property ---\n";
$p->dyn = 1;
var_dump($u->dyn ?? 'none');
echo "--- log ---\n";
echo implode(",", $log), "\n";

echo "--- mock properties ---\n";
$m = Proxy::mock(User::class, propertyInterceptor: fn (PI $prop) => $prop->property() === 'id'
    ? ($prop->operation() === Op::GET ? 4242 : ($prop->operation() === Op::ISSET))
    : $prop->proceed(...($prop->operation() === Op::SET ? [$prop->value()] : [])));
var_dump($m->id, isset($m->id));
try {
    $m->name;
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
try {
    $m->secret;
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
try {
    $m->id = 3;
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
echo "--- readonly class + hooks ---\n";
final readonly class Point {
    public function __construct(public int $x, public int $y) {
    }
}
$pt = Proxy::wrap(new Point(1, 2), propertyInterceptor: fn (PI $p) => $p->property() === 'x'
    ? 100 : $p->proceed(...($p->operation() === Op::SET ? [$p->value()] : [])));
var_dump($pt->x, $pt->y);
try {
    $pt->y = 5;
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
try {
    $pt->z = 5;
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
class Hooked {
    public string $upper = '' {
        get => strtoupper($this->upper);
        set => $this->upper = trim($value);
    }
    public string $virt { get => 'virtual:' . $this->upper; }
}
$h = new Hooked();
$hp = Proxy::wrap($h, propertyInterceptor: function (PI $prop) {
    echo "[", $prop->operation()->name, " ", $prop->property(), "]";
    return $prop->operation() === Op::SET ? $prop->proceed($prop->value()) : $prop->proceed();
});
$hp->upper = '  hi ';
var_dump($hp->upper, $hp->virt, $h->upper);
try {
    $hp->virt = 'x';
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
echo "--- var_dump / cast ---\n";
var_dump((array) $pt);
var_dump($pt);
echo "done\n";
--EXPECTF--
--- GET/SET name ---
string(9) "Fake real"
string(3) "BOB"
string(8) "Fake BOB"
--- untouched prop delegates ---
int(0)
int(5)
int(6)
array(2) {
  [0]=>
  string(1) "a"
  [1]=>
  string(1) "b"
}
--- isset/empty/unset ---
bool(true)
bool(false)
bool(false)
bool(false)
Error: Typed property User::$friend must not be accessed before initialization
--- visibility preserved ---
Error: Cannot access protected property User::$secret
Error: Cannot access private property User::$hidden
bool(false)
--- readonly preserved ---
int(7)
Error: Cannot modify readonly property User::$id
--- asymmetric visibility ---
string(4) "user"
Error: Cannot modify protected(set) property User::$role from global scope
string(5) "admin"
string(5) "admin"
--- dynamic property ---

Deprecated: Creation of dynamic property User::$dyn is deprecated in %s010-property-interception.php on line %d
int(1)
--- log ---
GET:name,SET:name,GET:name,GET:count,SET:count,GET:count,SET:count,GET:tags,ISSET:name,ISSET:friend,ISSET:count,GET:count,ISSET:nope,UNSET:friend,GET:friend,GET:id,GET:role,GET:role,SET:dyn
--- mock properties ---
int(4242)
bool(true)
Proxy\Exception\UnconfiguredProperty: Unconfigured GET of property User::$name on mock
Error: Cannot access protected property User::$secret
Error: Cannot modify protected(set) readonly property User::$id from global scope
--- readonly class + hooks ---
int(100)
int(2)
Error: Cannot modify readonly property Point::$y
Error: Cannot create dynamic property Point::$z
[SET upper][GET upper][GET virt]string(2) "HI"
string(10) "virtual:HI"
string(2) "HI"
Error: Property Hooked::$virt is read-only
--- var_dump / cast ---
array(2) {
  ["x"]=>
  int(1)
  ["y"]=>
  int(2)
}
object(PointProxy_2)#%d (2) {
  ["x"]=>
  int(1)
  ["y"]=>
  int(2)
}
done
