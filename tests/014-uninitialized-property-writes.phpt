--TEST--
Writable middleware fetches preserve native initialization and non-nullable reference errors
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;

class UninitializedStorage {
    public array $items;
    public int $number;
    public ?array $optional;
    public stdClass $object;
    public ?stdClass $optionalObject;
}

function verify(bool $ok): void {
    if (!$ok) {
        throw new RuntimeException('Unexpected property state');
    }
}

function mustNotReceiveReference(mixed &$value): void {
    throw new RuntimeException('Uninitialized non-nullable property reached callback');
}

function writeReference(mixed &$value): void {
    $value = ['reference'];
}

foreach (['native', 'plain', 'intercepted'] as $mode) {
    foreach ([false, true] as $dynamic) {
        $target = new UninitializedStorage;
        $object = match ($mode) {
            'native' => $target,
            'plain' => Proxy::wrap($target),
            'intercepted' => Proxy::wrap($target, propertyInterceptor: fn &($call) => $call->proceed()),
        };
        try {
            if ($dynamic) {
                $name = 'items';
                $reference =& $object->$name;
            } else {
                $reference =& $object->items;
            }
            throw new RuntimeException('Missing non-nullable reference error');
        } catch (Error $error) {
            verify($error->getMessage() === 'Cannot access uninitialized non-nullable property UninitializedStorage::$items by reference');
        }
        try {
            $call = 'mustNotReceiveReference';
            if ($dynamic) {
                $name = 'items';
                $call($object->$name);
            } else {
                $call($object->items);
            }
        } catch (Error $error) {
            verify($error->getMessage() === 'Cannot access uninitialized non-nullable property UninitializedStorage::$items by reference');
        }
        verify(!(new ReflectionProperty($target, 'items'))->isInitialized($target));
        try {
            if ($dynamic) {
                $name = 'number';
                $object->$name[] = 1;
            } else {
                $object->number[] = 1;
            }
            throw new RuntimeException('Missing scalar array-initialization error');
        } catch (TypeError $error) {
            verify($error->getMessage() === 'Cannot auto-initialize an array inside property UninitializedStorage::$number of type int');
        }
        verify(!(new ReflectionProperty($target, 'number'))->isInitialized($target));
        if ($dynamic) {
            $name = 'items';
            $object->$name[] = 1;
        } else {
            $object->items[] = 1;
        }
        verify($target->items === [1]);
        unset($object, $target);
    }
    echo "$mode: initialization and native errors\n";
}

// Returning null without an original fetch must still fail the read contract.
$target = new UninitializedStorage;
$object = Proxy::wrap($target, propertyInterceptor: fn ($call) => null);
try {
    $object->items[] = 1;
    throw new RuntimeException('Replacement null bypassed property type');
} catch (TypeError) {
    echo "replacement null remains invalid\n";
}
verify(!(new ReflectionProperty($target, 'items'))->isInitialized($target));
unset($object, $target);

$target = new UninitializedStorage;
$object = Proxy::wrap($target, propertyInterceptor: function ($call) use ($target) {
    $call->proceed();
    $target->items = [];
    return null;
});
try {
    $object->items[] = 1;
    throw new RuntimeException('Null replacement bypassed type after initializing storage');
} catch (TypeError) {
    echo "changed storage validates deferred null\n";
}
verify($target->items === []);
unset($object, $target);

foreach ([false, true] as $dynamic) {
    foreach (['items', 'optional'] as $property) {
        $target = new UninitializedStorage;
        $object = Proxy::wrap($target, propertyInterceptor: function &($call) use (&$object) {
            $object = null;
            return $call->proceed();
        });
        if ($dynamic) {
            $object->$property[] = 2;
        } elseif ($property === 'items') {
            $object->items[] = 2;
        } else {
            $object->optional[] = 2;
        }
        verify($object === null && $target->$property === [2]);
        unset($target);
    }
    $target = new UninitializedStorage;
    $object = Proxy::wrap($target, propertyInterceptor: function &($call) use (&$object) {
        $object = null;
        return $call->proceed();
    });
    try {
        if ($dynamic) {
            $property = 'items';
            $reference =& $object->$property;
        } else {
            $reference =& $object->items;
        }
        throw new RuntimeException('Released proxy bypassed non-nullable reference error');
    } catch (Error $error) {
        verify($error->getMessage() === 'Cannot access uninitialized non-nullable property UninitializedStorage::$items by reference');
    }
    verify($object === null && !(new ReflectionProperty($target, 'items'))->isInitialized($target));
    unset($target);
}
echo "released proxy preserves initialization and reference errors\n";

$object = Proxy::wrap(new UninitializedStorage, propertyInterceptor: function &($call) use (&$object) {
    $object = null;
    return $call->proceed();
});
$target = WeakReference::create(Proxy::target($object));
$object->items[] = 3;
verify($object === null && $target->get() === null);
echo "released target leaves no borrowed storage\n";

foreach (['native', 'plain', 'intercepted', 'released'] as $mode) {
    foreach ([false, true] as $dynamic) {
        foreach (['object', 'optionalObject'] as $property) {
            $target = new UninitializedStorage;
            $object = match ($mode) {
                'native' => $target,
                'plain' => Proxy::wrap($target),
                'intercepted' => Proxy::wrap($target, propertyInterceptor: fn &($call) => $call->proceed()),
                'released' => Proxy::wrap($target, propertyInterceptor: function &($call) use (&$object) {
                    $object = null;
                    return $call->proceed();
                }),
            };
            try {
                if ($dynamic) {
                    $object->$property->inner = 1;
                } elseif ($property === 'object') {
                    $object->object->inner = 1;
                } else {
                    $object->optionalObject->inner = 1;
                }
                throw new RuntimeException('Missing uninitialized nested-object error');
            } catch (Error $error) {
                verify($error->getMessage() === 'Attempt to assign property "inner" on null');
            }
            verify(!(new ReflectionProperty($target, $property))->isInitialized($target));
            verify($mode !== 'released' || $object === null);
            unset($object, $target);
        }
    }
    echo "$mode: nested object writes leave storage uninitialized\n";
}

foreach ([false, true] as $dynamicCall) {
    foreach ([false, true] as $dynamicProperty) {
        foreach (['items', 'optional'] as $property) {
            $target = new UninitializedStorage;
            $object = Proxy::wrap($target, propertyInterceptor: function &($call) use (&$object) {
                $object = null;
                return $call->proceed();
            });
            try {
                if ($dynamicCall) {
                    $call = $property === 'items' ? 'mustNotReceiveReference' : 'writeReference';
                    if ($dynamicProperty) {
                        $call($object->$property);
                    } elseif ($property === 'items') {
                        $call($object->items);
                    } else {
                        $call($object->optional);
                    }
                } elseif ($property === 'items') {
                    if ($dynamicProperty) {
                        mustNotReceiveReference($object->$property);
                    } else {
                        mustNotReceiveReference($object->items);
                    }
                } else {
                    if ($dynamicProperty) {
                        writeReference($object->$property);
                    } else {
                        writeReference($object->optional);
                    }
                }
                verify($property === 'optional' && $target->optional === ['reference']);
            } catch (Error $error) {
                verify($property === 'items');
                verify($error->getMessage() === 'Cannot access uninitialized non-nullable property UninitializedStorage::$items by reference');
                verify(!(new ReflectionProperty($target, 'items'))->isInitialized($target));
            }
            verify($object === null);
            unset($object, $target);
        }
    }
}
echo "released proxy preserves direct and dynamic reference arguments\n";
?>
--EXPECT--
native: initialization and native errors
plain: initialization and native errors
intercepted: initialization and native errors
replacement null remains invalid
changed storage validates deferred null
released proxy preserves initialization and reference errors
released target leaves no borrowed storage
native: nested object writes leave storage uninitialized
plain: nested object writes leave storage uninitialized
intercepted: nested object writes leave storage uninitialized
released: nested object writes leave storage uninitialized
released proxy preserves direct and dynamic reference arguments
