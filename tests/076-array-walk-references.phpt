--TEST--
array_walk and array_walk_recursive preserve target writes, reference types and source lifetimes
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;

function verify(bool $ok): void
{
    if (!$ok) {
        throw new RuntimeException('array_walk reference semantics changed');
    }
}
function compare(string $label, Closure $scenario): void
{
    $native = $scenario(false);
    $proxied = $scenario(true);
    verify($native === $proxied);
    echo $label, ": same\n";
}
#[AllowDynamicProperties]
class WalkStorage
{
    public int $number = 1;
    protected int $hidden = 2;
    private int $secret = 3;
    public array $nested = ['a' => 4, 'b' => ['c' => 5]];
    public int $uninitialized;
}
compare('declared, non-public and dynamic writes', function (bool $wrapped) {
    $target = new WalkStorage;
    $target->dynamic = 6;
    $target->{'0'} = 7;
    $view = $wrapped ? Proxy::wrap($target) : $target;
    $keys = [];
    array_walk($view, function (&$value, $key) use (&$keys, $target, $view) {
        $keys[] = $key;
        if (is_int($value)) {
            $value += 10;
        }
        if ($key === 'number') {
            verify($target->number === 11 && $view->number === 11);
            $view->number = 12;
            verify($value === 12);
        }
    });
    $copy = get_mangled_object_vars($view);
    $copy['number'] = 99;
    $copy['dynamic'] = 99;
    verify($target->number === 12 && $target->dynamic === 16);
    return [$keys, (array) $target];
});
compare('recursive array writes', function (bool $wrapped) {
    $target = new WalkStorage;
    $view = $wrapped ? Proxy::wrap($target) : $target;
    array_walk_recursive($view, function (&$value) {
        $value *= 2;
    });
    return (array) $target;
});
compare('typed callback references and unset source', function (bool $wrapped) {
    $target = new WalkStorage;
    $view = $wrapped ? Proxy::wrap($target) : $target;
    $kept = [];
    $error = null;
    array_walk($view, function (&$value, $key) use ($target, &$kept, &$error) {
        if ($key !== 'number') {
            return;
        }
        $kept[0] = & $value;
        try {
            $value = 'invalid';
        } catch (TypeError $e) {
            $error = $e->getMessage();
        }
        verify($target->number === 1);
        unset($target->number);
        $value = 'detached';
    });
    verify($kept[0] === 'detached');
    $kept[0] = ['untyped'];
    return [$error, $kept, (array) $target];
});
compare('reference survives source destruction without stale type', function (bool $wrapped) {
    $target = new WalkStorage;
    $view = $wrapped ? Proxy::wrap($target) : $target;
    $kept = [];
    array_walk($view, function (&$value, $key) use (&$kept) {
        if ($key === 'number') {
            $kept[0] = & $value;
        }
    });
    $message = null;
    try {
        $kept[0] = 'invalid';
    } catch (TypeError $e) {
        $message = $e->getMessage();
    }
    unset($target, $view);
    $kept[0] = 'detached';
    return [$message, $kept];
});
compare('target hash growth and removal during callbacks', function (bool $wrapped) {
    $target = new WalkStorage;
    $target->before = 8;
    $target->after = 9;
    $view = $wrapped ? Proxy::wrap($target) : $target;
    $keys = [];
    array_walk($view, function (&$value, $key) use ($target, $view, &$keys) {
        $keys[] = $key;
        if ($key === 'before') {
            unset($target->before);
            for ($i = 0; $i < 40; $i++) {
                $target->{'new' . $i} = $i;
            }
            verify($view->number === 2);
        }
        if (is_int($value)) {
            $value++;
        }
    });
    return [$keys, (array) $target];
});
class WalkAliases
{
    public int $a = 1;
    public int $b = 2;
    public int $c = 3;
}
function aliasTarget(bool $dynamic): object
{
    if (!$dynamic) {
        return new WalkAliases;
    }
    $target = new stdClass;
    $target->a = 1;
    $target->b = 2;
    $target->c = 3;
    return $target;
}
foreach ([false, true] as $dynamic) {
    $kind = $dynamic ? 'dynamic' : 'declared';
    foreach (['mangled', 'cast'] as $purpose) {
        foreach ([false, true] as $byReference) {
            compare(
                "$kind $purpose " . ($byReference ? 'reference' : 'value') . ' callback aliases',
                function (bool $wrapped) use ($dynamic, $purpose, $byReference) {
                    $target = aliasTarget($dynamic);
                    $view = $wrapped ? Proxy::wrap($target) : $target;
                    $inspect = function ($key) use ($view, $purpose) {
                        if ($key === 'a') {
                            $copy = $purpose === 'mangled' ? get_mangled_object_vars($view) : (array) $view;
                            $copy['a'] = 10;
                            $copy['b'] = 20;
                            $copy['c'] = 30;
                        }
                    };
                    if ($byReference) {
                        array_walk($view, function (&$value, $key) use ($inspect) {
                            $inspect($key);
                        });
                    } else {
                        array_walk($view, function ($value, $key) use ($inspect) {
                            $inspect($key);
                        });
                    }
                    return (array) $target;
                }
            );
        }
    }
    foreach ([false, true] as $throw) {
        compare(
            "$kind nested " . ($throw ? 'throwing' : 'successful') . ' walk',
            function (bool $wrapped) use ($dynamic, $throw) {
                $target = aliasTarget($dynamic);
                $view = $wrapped ? Proxy::wrap($target) : $target;
                $keys = [];
                array_walk($view, function (&$value, $key) use ($target, $view, $throw, &$keys) {
                    $keys[] = "outer:$key";
                    if (count($keys) > 12) {
                        throw new LogicException('Walk restarted');
                    }
                    if ($key === 'a') {
                        try {
                            array_walk($view, function (&$nested, $key) use ($view, $throw, &$keys) {
                                $keys[] = "inner:$key";
                                $nested++;
                                if ($throw) {
                                    throw new RuntimeException('stop inner');
                                }
                                $copy = get_mangled_object_vars($view);
                                $copy['b'] = 40;
                            });
                        } catch (RuntimeException) {
                        }
                        $copy = get_mangled_object_vars($view);
                        $copy['a'] = 10;
                        $copy['b'] = 20;
                    }
                    $value++;
                });
                return [$keys, (array) $target];
            }
        );
    }
    compare("$kind caught callback exception releases temporary aliases", function (bool $wrapped) use ($dynamic) {
        $target = aliasTarget($dynamic);
        $view = $wrapped ? Proxy::wrap($target) : $target;
        try {
            array_walk($view, function (&$value) {
                throw new RuntimeException('stop walk');
            });
        } catch (RuntimeException) {
        }
        // Inspect the target directly, without giving a proxy handler a chance
        // to clean up aliases left by the failed walk.
        $copy = get_mangled_object_vars($target);
        $copy['a'] = 99;
        $copy['b'] = 99;
        verify($target->a === 1 && $target->b === 2);
        array_walk($view, function (&$value) {
            $value++;
        });
        return (array) $target;
    });
}
echo "done\n";
?>
--EXPECT--
declared, non-public and dynamic writes: same
recursive array writes: same
typed callback references and unset source: same
reference survives source destruction without stale type: same
target hash growth and removal during callbacks: same
declared mangled value callback aliases: same
declared mangled reference callback aliases: same
declared cast value callback aliases: same
declared cast reference callback aliases: same
declared nested successful walk: same
declared nested throwing walk: same
declared caught callback exception releases temporary aliases: same
dynamic mangled value callback aliases: same
dynamic mangled reference callback aliases: same
dynamic cast value callback aliases: same
dynamic cast reference callback aliases: same
dynamic nested successful walk: same
dynamic nested throwing walk: same
dynamic caught callback exception releases temporary aliases: same
done
