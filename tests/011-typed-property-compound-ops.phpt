--TEST--
Typed properties keep their contract through compound assignments and by-reference fetches
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;
use Proxy\PropertyInvocation as PI;
use Proxy\PropertyOperation as Op;

class Bag {
    public int $n = 1;
    public array $arr = [1];
    public ?string $s = 'a';
}
foreach (['plain', 'intercepted'] as $mode) {
    echo "--- $mode ---\n";
    $target = new Bag();
    $interceptor = $mode === 'plain' ? null : function &(PI $invocation) {
        if ($invocation->operation() === Op::SET) {
            return $invocation->proceed($invocation->value());
        }
        return $invocation->proceed();
    };
    $proxy = Proxy::wrap($target, propertyInterceptor: $interceptor);

    $proxy->n += 5;
    $proxy->n++;
    try {
        $proxy->n .= 'x';
    } catch (TypeError $e) {
        echo get_class($e), ": ", $e->getMessage(), "\n";
    }
    $reference = &$proxy->n;
    $reference = 42;
    try {
        $reference = 'str';
    } catch (TypeError $e) {
        echo get_class($e), ": ", $e->getMessage(), "\n";
    }
    foreach ($proxy->arr as &$item) {
        $item++;
    }
    unset($item);
    $proxy->s .= 'b';
    var_dump($target->n, $target->arr, $target->s);
    unset($reference);
}
echo "done\n";
--EXPECT--
--- plain ---
TypeError: Cannot assign string to property Bag::$n of type int
TypeError: Cannot assign string to reference held by property Bag::$n of type int
int(42)
array(1) {
  [0]=>
  int(2)
}
string(2) "ab"
--- intercepted ---
TypeError: Cannot assign string to property Bag::$n of type int
TypeError: Cannot assign string to reference held by property Bag::$n of type int
int(42)
array(1) {
  [0]=>
  int(2)
}
string(2) "ab"
done
