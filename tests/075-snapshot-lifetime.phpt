--TEST--
Raw snapshots detach cached values before reentrant destructors release or inspect the proxy
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;

function verify(bool $ok): void
{
    if (!$ok) {
        throw new RuntimeException('Snapshot lifetime changed');
    }
}
class SnapshotHolder
{
    public object $first;
    public object $second;
    public int $number = 7;
}
class SnapshotPayload
{
    public function __construct(private Closure $cleanup)
    {
    }

    public function __destruct()
    {
        ($this->cleanup)();
    }
}

$events = [];
$target = new SnapshotHolder;
$target->first = new SnapshotPayload(function () use (&$events) {
    $events[] = 'first';
    $GLOBALS['proxy'] = null;
});
$target->second = new SnapshotPayload(function () use (&$events) {
    $events[] = 'second';
});
$proxy = Proxy::wrap($target);
$weak = WeakReference::create($proxy);
get_mangled_object_vars($proxy);
unset($target->first, $target->second);
verify($events === []);
verify($proxy->number === 7);
verify($proxy === null && $weak->get() === null);
verify($events === ['first', 'second']);
echo "read survives destruction of all cached values and last proxy reference\n";

$events = [];
$target = new SnapshotHolder;
$target->first = new SnapshotPayload(function () use (&$events) {
    $events[] = 'first';
    $values = get_mangled_object_vars($GLOBALS['proxy']);
    verify($values === ['number' => 7]);
    $GLOBALS['proxy']->number = 8;
});
$target->second = new SnapshotPayload(function () use (&$events) {
    $events[] = 'second';
    verify(get_mangled_object_vars($GLOBALS['proxy']) === ['number' => 8]);
});
$proxy = Proxy::wrap($target);
get_mangled_object_vars($proxy);
unset($target->first, $target->second);
verify($proxy->number === 8);
verify($events === ['first', 'second']);
verify(get_mangled_object_vars($proxy) === ['number' => 8]);
unset($proxy, $target);
echo "nested snapshots see cleared slots and preserve their own replacement values\n";

#[AllowDynamicProperties]
class SnapshotDynamic
{
    public int $number = 1;
}
$target = new SnapshotDynamic;
$events = [];
$target->payload = new SnapshotPayload(function () use (&$events, $target) {
    $events[] = 'payload';
    for ($i = 0; $i < 40; $i++) {
        $target->{'new' . $i} = $i;
    }
    get_mangled_object_vars($GLOBALS['proxy']);
});
$proxy = Proxy::wrap($target);
get_mangled_object_vars($proxy);
unset($target->payload);
verify(get_mangled_object_vars($proxy)['new39'] === 39);
verify($events === ['payload']);
verify($proxy->number === 1);
unset($proxy, $target);
echo "destructor-driven target rehash is reflected in the next raw view\n";
$events = [];
$target = new SnapshotDynamic;
$target->payload = new SnapshotPayload(function () use (&$events, $target) {
    $events[] = 'walk payload';
    for ($i = 0; $i < 40; $i++) {
        $target->{'added' . $i} = $i;
    }
    get_mangled_object_vars($GLOBALS['proxy']);
    $GLOBALS['proxy']->number = 12;
});
$target->tail = 2;
$proxy = Proxy::wrap($target);
array_walk($proxy, function (&$value, $key) use ($target) {
    if ($key === 'number') {
        unset($target->payload);
    }
    if (is_int($value)) {
        $value++;
    }
});
verify($events === ['walk payload']);
verify($target->number === 12 && $target->added39 === 40);
unset($proxy, $target);
echo "active walk survives cached destructor reentry and target rehash\n";

$events = [];
$target = new SnapshotHolder;
$target->first = new SnapshotPayload(function () use (&$events) {
    $events[] = 'throw';
    throw new RuntimeException('cached destructor');
});
$calls = 0;
$proxy = Proxy::wrap($target, propertyInterceptor: function ($call) use (&$calls) {
    $calls++;
    return $call->proceed();
});
get_mangled_object_vars($proxy);
unset($target->first);
try {
    $proxy->number;
    throw new LogicException('Missing destructor exception');
} catch (RuntimeException $e) {
    verify($e->getMessage() === 'cached destructor');
}
verify($calls === 0 && $events === ['throw']);
verify($proxy->number === 7 && $calls === 1);
unset($proxy, $target);
echo "throwing cleanup aborts the pending operation before interception\n";
?>
--EXPECT--
read survives destruction of all cached values and last proxy reference
nested snapshots see cleared slots and preserve their own replacement values
destructor-driven target rehash is reflected in the next raw view
active walk survives cached destructor reentry and target rehash
throwing cleanup aborts the pending operation before interception
