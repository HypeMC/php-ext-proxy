--TEST--
Property proceed preserves native getter references through reference-returning callbacks
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;
use Proxy\PropertyInvocation as PI;
use Proxy\PropertyOperation as Op;

function verify(bool $condition): void {
    if (!$condition) {
        throw new RuntimeException('Property reference semantics changed');
    }
}

function expectTypeError(Closure $operation): void {
    try {
        $operation();
    } catch (TypeError) {
        return;
    }
    throw new RuntimeException('Missing reference type constraint');
}

function &forward(PI $call): mixed {
    if ($call->operation() === Op::SET) {
        return $call->proceed($call->value());
    }
    return $call->proceed();
}

class HookReferences {
    public int $number = 1 { &get => $this->number; }
    public array $items = [] { &get => $this->items; }
    public int $source = 1;
    public int $sets = 0;
    public int $virtual {
        &get => $this->source;
        set {
            ++$this->sets;
            $this->source = $value;
        }
    }
}

verify((new ReflectionMethod(PI::class, 'proceed'))->returnsReference());
foreach (['native', 'plain', 'intercepted'] as $mode) {
    $target = new HookReferences;
    $object = match ($mode) {
        'native' => $target,
        'plain' => Proxy::wrap($target),
        'intercepted' => Proxy::wrap($target, propertyInterceptor: 'forward'),
    };

    $number =& $object->number;
    $name = 'virtual';
    $virtual =& $object->$name;
    $number = 7;
    $virtual = 9;
    verify($target->number === 7 && $target->source === 9 && $target->sets === 0);
    verify($object->number === 7 && $object->$name === 9);
    expectTypeError(function () use (&$number) {
        $number = 'invalid';
    });
    expectTypeError(function () use (&$virtual) {
        $virtual = 'invalid';
    });
    $object->items[] = 'appended';
    $items =& $object->items;
    $items[] = 'aliased';
    verify($target->items === ['appended', 'aliased']);
    expectTypeError(function () use (&$items) {
        $items = 'invalid';
    });
    $object->virtual = 12;
    verify($virtual === 12 && $target->sets === 1);

    unset($object, $target);
    // Hook-backed storage contributes real native type sources, which must
    // disappear when that storage is destroyed even while aliases survive.
    $number = 'released';
    $virtual = 'released';
    $items = 'released';
    unset($number, $virtual, $items);
    echo "$mode: backed and virtual references, setters and source lifetime\n";
}

// Every PHP callback chooses whether to preserve the alias. Returning by
// value must not be guessed to mean the same reference because values match.
foreach (['value', 'routed-value', 'value-delegate', 'reference-local'] as $mode) {
    $target = new HookReferences;
    $byValue = fn (PI $call) => $call->proceed();
    $byReference = fn &(PI $call) => $call->proceed();
    $object = match ($mode) {
        'value' => Proxy::wrap($target, propertyInterceptor: $byValue),
        'routed-value' => Proxy::wrap($target, propertyInterceptor: fn (PI $call) =>
            $call->property() === 'number' ? $byValue($call) : forward($call)),
        'value-delegate' => Proxy::wrap($target, propertyInterceptor: fn (PI $call) => $byReference($call)),
        'reference-local' => Proxy::wrap($target, propertyInterceptor: function &(PI $call) use ($byValue) {
            $local = $byValue($call);
            return $local;
        }),
    };
    $notices = 0;
    set_error_handler(function (int $severity, string $message) use (&$notices): bool {
        if ($severity !== E_NOTICE || !str_contains($message, 'Indirect modification')) {
            throw new RuntimeException($message);
        }
        ++$notices;
        return true;
    });
    $detached =& $object->number;
    restore_error_handler();
    $detached = 99;
    verify($target->number === 1);
    // A reference-returning outer callback can return a reference to its own
    // temporary, but it cannot restore the native alias lost by its child.
    verify($notices === ($mode === 'reference-local' ? 0 : 1));
    unset($detached, $object, $target);
    echo "$mode: value callback detaches\n";
}

class AlternatingReferences {
    public array $first = [1];
    public array $second = [2];
    public int $reads = 0;
    public array $value {
        &get {
            if (++$this->reads % 2) {
                return $this->first;
            }
            return $this->second;
        }
    }
}
$target = new AlternatingReferences;
$interceptorCalls = 0;
$object = Proxy::wrap($target,
    propertyInterceptor: function &(PI $call) use (&$interceptorCalls) {
        ++$interceptorCalls;
        if ($call->property() !== 'value') {
            return forward($call);
        }
        $first =& $call->proceed();
        $second =& $call->proceed();
        $first[] = 'first';
        $second[] = 'second';
        return $first;
    },
);
$reference =& $object->value;
$reference[] = 'caller';
verify($target->first === [1, 'first', 'caller']);
verify($target->second === [2, 'second']);
verify($target->reads === 2 && $interceptorCalls === 1);
echo "repeated proceed retains distinct getter aliases\n";

class OrdinaryProperty {
    public int $value = 1;
}
$target = new OrdinaryProperty;
$object = Proxy::wrap($target, propertyInterceptor: 'forward');
verify($object->value === 1 && isset($object->value));
$object->value = 2;
verify($target->value === 2);
unset($object->value);
verify(!isset($object->value));
echo "ordinary GET SET ISSET UNSET remain valid\n";

$target = new OrdinaryProperty;
$object = Proxy::wrap($target, propertyInterceptor: fn &(PI $call) => $call->proceed());
$reference =& $object->value;
$reference = 7;
verify($target->value === 7);
expectTypeError(function () use (&$reference) {
    $reference = 'invalid';
});
echo "ordinary property forward preserves its storage reference\n";

$target = new OrdinaryProperty;
$object = Proxy::wrap($target, propertyInterceptor: function &(PI $call) {
    if ($call->property() !== 'value') {
        return forward($call);
    }
    $reference =& $call->proceed();
    ++$reference;
    return $reference;
});
$reference =& $object->value;
verify($target->value === 2 && $reference === 2);
$reference = 3;
verify($target->value === 3);
echo "callback can mutate the ordinary downstream reference\n";

$external = 1;
$target = new OrdinaryProperty;
$object = Proxy::wrap($target, propertyInterceptor: function &(PI $call) use (&$external) {
    if ($call->property() !== 'value') {
        return forward($call);
    }
    return $external;
});
$reference =& $object->value;
++$reference;
verify($external === 2 && $target->value === 1);
echo "equal-valued explicit reference retains its chosen identity\n";

class UninitializedReferences {
    public ?int $value;
    public array $items;
    public ?stdClass $object;
}
foreach ([false, true] as $dynamic) {
    $target = new UninitializedReferences;
    $external = null;
    $object = Proxy::wrap($target, propertyInterceptor: function &(PI $call) use (&$external) {
        if ($call->property() !== 'value') {
            return forward($call);
        }
        return $external;
    });
    if ($dynamic) {
        $name = 'value';
        $reference =& $object->$name;
    } else {
        $reference =& $object->value;
    }
    $reference = 8;
    verify($external === 8 && !(new ReflectionProperty($target, 'value'))->isInitialized($target));
    unset($reference, $object, $target, $external);
}
echo "explicit null reference does not initialize unrelated target storage\n";

foreach ([false, true] as $released) {
    $target = new UninitializedReferences;
    $object = Proxy::wrap($target, propertyInterceptor: function &(PI $call) use (&$object, $released) {
        if ($released) {
            $object = null;
        }
        return $call->proceed();
    });
    $reference =& $object->value;
    $reference = 9;
    verify($target->value === 9);
    expectTypeError(function () use (&$reference) {
        $reference = 'invalid';
    });
    unset($reference, $object, $target);

    $target = new UninitializedReferences;
    $object = Proxy::wrap($target, propertyInterceptor: function &(PI $call) use (&$object, $released) {
        if ($released) {
            $object = null;
        }
        return $call->proceed();
    });
    $object->items[] = 10;
    verify($target->items === [10]);
    unset($object, $target);

    $target = new UninitializedReferences;
    $object = Proxy::wrap($target, propertyInterceptor: function &(PI $call) use (&$object, $released) {
        if ($released) {
            $object = null;
        }
        return $call->proceed();
    });
    try {
        $object->object->inner = 1;
        throw new RuntimeException('Reference continuation initialized nested object storage');
    } catch (Error $error) {
        verify($error->getMessage() === 'Attempt to assign property "inner" on null');
    }
    verify(!(new ReflectionProperty($target, 'object'))->isInitialized($target));
    unset($object, $target);
}
echo "reference continuations preserve uninitialized fetch context and lifetime\n";

class ValueGetter {
    public int $value { get => 1; }
}
foreach ([false, true] as $wrapped) {
    $target = new ValueGetter;
    $object = $wrapped ? Proxy::wrap($target, propertyInterceptor: 'forward') : $target;
    try {
        $reference =& $object->value;
        throw new RuntimeException('Value getter became referenceable');
    } catch (Error $error) {
        verify($error->getMessage() === 'Indirect modification of ValueGetter::$value is not allowed');
    }
}
echo "value getter retains native reference restriction\n";
?>
--EXPECT--
native: backed and virtual references, setters and source lifetime
plain: backed and virtual references, setters and source lifetime
intercepted: backed and virtual references, setters and source lifetime
value: value callback detaches
routed-value: value callback detaches
value-delegate: value callback detaches
reference-local: value callback detaches
repeated proceed retains distinct getter aliases
ordinary GET SET ISSET UNSET remain valid
ordinary property forward preserves its storage reference
callback can mutate the ordinary downstream reference
equal-valued explicit reference retains its chosen identity
explicit null reference does not initialize unrelated target storage
reference continuations preserve uninitialized fetch context and lifetime
value getter retains native reference restriction
