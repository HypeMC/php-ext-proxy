--TEST--
Writable property callbacks choose reference identity without resolving unrelated target storage
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;
use Proxy\PropertyInvocation as PI;
use Proxy\PropertyOperation as Op;

function check(bool $condition): void {
    if (!$condition) {
        throw new RuntimeException('Property reference identity changed');
    }
}

class DeferredStorage {
    public array $items = [];
}
foreach (['ghost', 'proxy'] as $kind) {
    $reflection = new ReflectionClass(DeferredStorage::class);
    $initializations = 0;
    $initializer = function () use (&$initializations) {
        ++$initializations;
        throw new RuntimeException('Unrequested initializer');
    };
    $target = $kind === 'ghost'
        ? $reflection->newLazyGhost($initializer)
        : $reflection->newLazyProxy($initializer);
    $external = [];
    $object = Proxy::wrap($target, propertyInterceptor: function &(PI $call) use (&$external) {
        if ($call->property() === 'items') {
            return $external;
        }
        return $call->proceed(...($call->operation() === Op::SET ? [$call->value()] : []));
    });
    $object->items[] = 1;
    $reference =& $object->items;
    $reference[] = 2;
    unset($object->items[0]);
    check($external === [1 => 2]);
    check($initializations === 0 && $reflection->isUninitializedLazyObject($target));
    unset($reference, $object, $target, $external);
    echo "$kind: handled access leaves lazy target untouched\n";
}

$target = new stdClass;
$external = [];
$object = Proxy::wrap($target, propertyInterceptor: function &(PI $call) use (&$external) {
    if ($call->property() === 'items') {
        return $external;
    }
    return $call->proceed(...($call->operation() === Op::SET ? [$call->value()] : []));
});
$object->items[] = 1;
$reference =& $object->items;
$reference[] = 2;
check($external === [1, 2] && get_object_vars($target) === []);
unset($reference, $object, $target, $external);
echo "handled dynamic access creates no target property\n";

class OrdinaryStorage {
    public int $number = 1;
    public array $items = [];
}
foreach (['literal', 'value', 'value-delegate', 'reference-local'] as $mode) {
    $target = new OrdinaryStorage;
    $value = fn (PI $call) => $call->proceed();
    $reference = fn &(PI $call) => $call->proceed();
    $object = match ($mode) {
        'literal' => Proxy::wrap($target, propertyInterceptor: fn (PI $call) => match ($call->property()) {
            'number' => 1,
            'items' => [],
            default => $call->proceed(...($call->operation() === Op::SET ? [$call->value()] : [])),
        }),
        'value' => Proxy::wrap($target, propertyInterceptor: $value),
        'value-delegate' => Proxy::wrap($target, propertyInterceptor: fn (PI $call) => $reference($call)),
        'reference-local' => Proxy::wrap($target, propertyInterceptor: function &(PI $call) use ($value) {
            $local = $value($call);
            return $local;
        }),
    };
    $notices = 0;
    set_error_handler(function (int $severity, string $message) use (&$notices): bool {
        check($severity === E_NOTICE && str_contains($message, 'Indirect modification'));
        ++$notices;
        return true;
    });
    $detached =& $object->number;
    $detached = 2;
    $object->items[] = 3;
    restore_error_handler();
    check($target->number === 1 && $target->items === []);
    check($notices === ($mode === 'reference-local' ? 0 : 2));
    unset($detached, $object, $target);
    echo "$mode: equal values never recover the target alias\n";
}

$target = (object) ['items' => []];
$object = Proxy::wrap($target, propertyInterceptor: function &(PI $call) use ($target) {
    if ($call->property() !== 'items') {
        return $call->proceed(...($call->operation() === Op::SET ? [$call->value()] : []));
    }
    $reference =& $call->proceed();
    unset($target->items);
    return $reference;
});
$reference =& $object->items;
$reference[] = 4;
check($reference === [4] && get_object_vars($target) === []);
unset($reference, $object, $target);
echo "removed downstream storage is not recreated\n";

class UninitializedStorage {
    public int $number;
    public ?int $optional;
}
$target = new UninitializedStorage;
$object = Proxy::wrap($target, propertyInterceptor: function &(PI $call) {
    $first =& $call->proceed();
    $call->proceed();
    return $first;
});
$value = 5;
$object->number =& $value;
$value = 6;
check($target->number === 6);
try {
    $value = 'invalid';
    throw new RuntimeException('Lost property type source');
} catch (TypeError) {
}
unset($value, $object, $target);
echo "repeated proceed preserves uninitialized reference assignment\n";

$target = new UninitializedStorage;
$object = Proxy::wrap($target, propertyInterceptor: function &(PI $call) {
    if ($call->property() !== 'optional') {
        return $call->proceed(...($call->operation() === Op::SET ? [$call->value()] : []));
    }
    $local = $call->proceed();
    return $local;
});
$value = 7;
try {
    $object->optional =& $value;
    throw new RuntimeException('Detached null selected target storage');
} catch (Error $error) {
    check($error->getMessage() === 'Cannot assign by reference to overloaded object');
}
check(!(new ReflectionProperty($target, 'optional'))->isInitialized($target));
echo "a local null reference cannot recover an uninitialized slot\n";

class RetainedStorage {
    public ?stdClass $value;
}
$saved = null;
$discarded = null;
$target = new RetainedStorage;
$object = Proxy::wrap($target, propertyInterceptor: function &(PI $call) use (&$saved, &$discarded) {
    if ($call->property() !== 'value') {
        return $call->proceed(...($call->operation() === Op::SET ? [$call->value()] : []));
    }
    $saved = $call;
    $first =& $call->proceed();
    $first = new stdClass;
    $discarded = WeakReference::create($first);
    return $call->proceed();
});
$value = null;
$object->value =& $value;
check($discarded->get() === null);
unset($target->value);
$late =& $saved->proceed();
$late = new stdClass;
$discarded = WeakReference::create($late);
unset($late);
check($discarded->get() === null);
unset($saved, $value, $object, $target);
echo "saved invocations release completed and late temporary references\n";

class CleanupAction {
    public function __construct(private Closure $action) {
    }

    public function __destruct() {
        ($this->action)();
    }
}
foreach (['initialize', 'throw'] as $mode) {
    $target = new UninitializedStorage;
    $object = Proxy::wrap($target, propertyInterceptor: function &(PI $call) use ($target, $mode, &$object) {
        if ($call->property() !== 'number') {
            return $call->proceed(...($call->operation() === Op::SET ? [$call->value()] : []));
        }
        $first =& $call->proceed();
        $first = new CleanupAction(function () use ($target, $mode, &$object) {
            $object = null;
            if ($mode === 'throw') {
                throw new RuntimeException('cleanup failed');
            }
            $target->number = 8;
        });
        return $call->proceed();
    });
    $value = 9;
    try {
        $object->number =& $value;
        throw new LogicException('Cleanup effects bypassed validation');
    } catch (Throwable $error) {
        check($mode === 'initialize' ? $error instanceof TypeError
            : $error instanceof RuntimeException && $error->getMessage() === 'cleanup failed');
    }
    check($object === null);
    check($mode === 'initialize' ? $target->number === 8 : !isset($target->number));
    unset($target, $value);
}
echo "temporary-reference cleanup is validated after destructor side effects\n";

$target = new UninitializedStorage;
$object = Proxy::wrap($target, propertyInterceptor: function &(PI $call) {
    if ($call->property() !== 'number') {
        return $call->proceed(...($call->operation() === Op::SET ? [$call->value()] : []));
    }
    $first =& $call->proceed();
    $second =& $call->proceed();
    $first = new CleanupAction(function () use (&$second) {
        $second = 8;
    });
    return $second;
});
$value = 9;
try {
    $object->number =& $value;
    throw new LogicException('Changed result selected uninitialized storage');
} catch (Error $error) {
    check($error->getMessage() === 'Cannot assign by reference to overloaded object');
}
check(!(new ReflectionProperty($target, 'number'))->isInitialized($target));
echo "cleanup changing the returned reference does not select target storage\n";
?>
--EXPECT--
ghost: handled access leaves lazy target untouched
proxy: handled access leaves lazy target untouched
handled dynamic access creates no target property
literal: equal values never recover the target alias
value: equal values never recover the target alias
value-delegate: equal values never recover the target alias
reference-local: equal values never recover the target alias
removed downstream storage is not recreated
repeated proceed preserves uninitialized reference assignment
a local null reference cannot recover an uninitialized slot
saved invocations release completed and late temporary references
temporary-reference cleanup is validated after destructor side effects
cleanup changing the returned reference does not select target storage
