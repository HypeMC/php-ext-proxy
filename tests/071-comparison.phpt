--TEST--
Comparison semantics of proxy objects
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;
class A
{
    public $v = 1;
}
$a = new A();
$p = Proxy::wrap($a);
$p2 = Proxy::wrap($a);
var_dump(
    $p == $a,
    $a == $p,
    $p == $p2,
    $p == $p,
    $p === $p,
    $p != $a,
    $p == null,
    $p == true,
    (bool) $p,
    $p == 1
);
$dt = new DateTimeImmutable('2024-01-02');
$pd = Proxy::wrap($dt);
var_dump($pd == $dt, $dt == $pd, $pd > new DateTimeImmutable('2000-01-01'));
--EXPECTF--

Notice: Object of class AProxy_1 could not be converted to int in %s071-comparison.php on line 20
bool(false)
bool(false)
bool(false)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
bool(false)
bool(false)
bool(false)
