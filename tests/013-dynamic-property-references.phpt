--TEST--
Literal and dynamic property references preserve types, initialization and source lifetimes
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;

class ReferenceBag {
    public int $number = 1;
    public int $required;
    public ?int $optional;
    public ?array $items;
    public readonly int $readonly;
    public protected(set) int $restricted = 1;

    public function __construct() {
        $this->readonly = 1;
    }
}

function check(bool $ok): void {
    if (!$ok) {
        throw new RuntimeException('Reference semantics changed');
    }
}

function expectTypeError(Closure $operation): void {
    try {
        $operation();
    } catch (TypeError) {
        return;
    }
    throw new RuntimeException('Missing property type check');
}

function &literalReference(object $object): mixed {
    return $object->number;
}

function &dynamicReference(object $object, string $property): mixed {
    return $object->$property;
}

// The same literal call site alternates between ordinary and proxy objects.
// Repeated fetches must not register duplicate property type sources.
foreach (['native', 'plain', 'intercepted'] as $mode) {
    $target = new ReferenceBag;
    $object = match ($mode) {
        'native' => $target,
        'plain' => Proxy::wrap($target),
        'intercepted' => Proxy::wrap($target, propertyInterceptor: fn &($call) => $call->proceed()),
    };
    $sibling = new ReferenceBag;
    for ($i = 0; $i < 12; $i++) {
        $literal =& literalReference($object);
        $dynamic =& dynamicReference($object, 'number');
        $literal++;
        check($dynamic === $target->number);
        expectTypeError(function () use (&$dynamic) {
            $dynamic = 'invalid';
        });
    }
    $sibling->number =& $dynamic;
    unset($target->number);
    expectTypeError(function () use (&$dynamic) {
        $dynamic = 'still typed';
    });
    unset($sibling->number);
    $dynamic = 'all sources removed';
    check($literal === 'all sources removed');
    unset($literal, $dynamic, $object, $target, $sibling);
    echo "$mode: mixed names and shared sources\n";
}

foreach (['native', 'plain', 'intercepted'] as $mode) {
    foreach ([false, true] as $dynamicName) {
        $target = new ReferenceBag;
        $object = match ($mode) {
            'native' => $target,
            'plain' => Proxy::wrap($target),
            'intercepted' => Proxy::wrap($target, propertyInterceptor: fn &($call) => $call->proceed()),
        };
        if ($dynamicName) {
            $name = 'optional';
            $reference =& $object->$name;
        } else {
            $reference =& $object->optional;
        }
        check($reference === null);
        check((new ReflectionProperty($target, 'optional'))->isInitialized($target));
        $reference = 42;
        check($target->optional === 42);
        expectTypeError(function () use (&$reference) {
            $reference = 'invalid';
        });
        if ($dynamicName) {
            $name = 'items';
            $object->$name[] = 'item';
        } else {
            $object->items[] = 'item';
        }
        check($target->items === ['item']);
        unset($object, $target);
        // Once storage dies, no stale type source may constrain its old alias.
        $reference = 'detached';
        unset($reference);
    }
    echo "$mode: nullable initialization and source lifetime\n";
}

foreach (['native', 'plain', 'intercepted'] as $mode) {
    $target = new ReferenceBag;
    $object = match ($mode) {
        'native' => $target,
        'plain' => Proxy::wrap($target),
        'intercepted' => Proxy::wrap($target, propertyInterceptor: fn &($call) => $call->proceed()),
    };
    foreach (['readonly', 'restricted', 'required'] as $name) {
        try {
            $reference =& $object->$name;
            throw new RuntimeException('Reference bypassed property restrictions');
        } catch (Error) {
        }
    }
    check(!(new ReflectionProperty($target, 'required'))->isInitialized($target));
    unset($object, $target);
    echo "$mode: readonly and asymmetric visibility\n";
}

foreach ([false, true] as $dynamicName) {
    $target = new ReferenceBag;
    $object = Proxy::wrap($target, propertyInterceptor: function &($call) use (&$object) {
        $object = null;
        return $call->proceed();
    });
    if ($dynamicName) {
        $name = 'optional';
        $reference =& $object->$name;
    } else {
        $reference =& $object->optional;
    }
    check($reference === null && $object === null);
    $reference = 7;
    check($target->optional === 7);
    expectTypeError(function () use (&$reference) {
        $reference = 'invalid';
    });
    unset($target);
    $reference = 'detached';
    unset($reference);
}
echo "nullable reference survives proxy release\n";
?>
--EXPECT--
native: mixed names and shared sources
plain: mixed names and shared sources
intercepted: mixed names and shared sources
native: nullable initialization and source lifetime
plain: nullable initialization and source lifetime
intercepted: nullable initialization and source lifetime
native: readonly and asymmetric visibility
plain: readonly and asymmetric visibility
intercepted: readonly and asymmetric visibility
nullable reference survives proxy release
