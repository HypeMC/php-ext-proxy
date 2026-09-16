--TEST--
Target lifetime, destructor semantics, cycle collection, weak references
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;
use Proxy\Invocation;

class Res
{
    public static int $destroyed = 0;

    public function __construct(public string $name)
    {
    }

    public function __destruct()
    {
        self::$destroyed++;
        echo "destruct {$this->name}\n";
    }

    public function hi(): string
    {
        return "hi {$this->name}";
    }
}

echo "--- target destructor runs exactly once, after proxy release ---\n";
$target = new Res('a');
$proxy = Proxy::wrap($target);
unset($proxy);
echo "proxy gone, destroyed=", Res::$destroyed, "\n";
unset($target);
echo "target gone, destroyed=", Res::$destroyed, "\n";

echo "--- proxy keeps target alive ---\n";
$proxy = Proxy::wrap(new Res('b'));
var_dump($proxy->hi());
echo "before unset proxy\n";
unset($proxy);
echo "after unset proxy\n";

echo "--- cycle: interceptor captures its own proxy ---\n";
$proxy = Proxy::wrap(new Res('c'), methodInterceptor: function (Invocation $call) use (&$proxy) {
    return $call->proceed(...$call->args()) . ' via ' . get_class($proxy);
});
var_dump($proxy->hi());
unset($proxy);
echo "collected: ", gc_collect_cycles(), "\n";

echo "--- cycle through stored invocation ---\n";
$store = new stdClass;
$proxy = Proxy::wrap(new Res('d'), methodInterceptor: function (Invocation $call) use ($store) {
    $store->call = $call;
    return 'stored';
});
$store->proxy = $proxy;
var_dump($proxy->hi());
unset($proxy, $store);
echo "collected: ", gc_collect_cycles(), "\n";

echo "--- weak reference to proxy ---\n";
$proxy = Proxy::mock(Res::class);
$weakProxy = WeakReference::create($proxy);
var_dump($weakProxy->get() === $proxy);
unset($proxy);
var_dump($weakProxy->get());

echo "--- many proxies / class reuse ---\n";
$proxies = [];
for ($i = 0; $i < 200; $i++) {
    $proxies[] = Proxy::wrap(
        new Res("n$i"),
        methodInterceptor: fn (Invocation $call) => strtoupper($call->proceed())
    );
}
var_dump(count(array_unique(array_map('get_class', $proxies))), $proxies[199]->hi());
$proxies = null;
echo "destroyed=", Res::$destroyed, "\n";

echo "--- mock has no destructor semantics ---\n";
$mock = Proxy::mock(Res::class);
unset($mock);
echo "destroyed=", Res::$destroyed, "\n";
echo "--- interface mock cycle via property interceptor ---\n";
interface Svc
{
    public function run(): string;
}
$interfaceProxy = Proxy::mock(Svc::class, methodInterceptor: fn () => 'ran', propertyInterceptor: function ($pi) use (&$interfaceProxy) {
    return 'x';
});
var_dump($interfaceProxy->run());
unset($interfaceProxy);
echo "collected: ", gc_collect_cycles(), "\n";
echo "done\n";
--EXPECTF--
--- target destructor runs exactly once, after proxy release ---
proxy gone, destroyed=0
destruct a
target gone, destroyed=1
--- proxy keeps target alive ---
string(4) "hi b"
before unset proxy
destruct b
after unset proxy
--- cycle: interceptor captures its own proxy ---
string(19) "hi c via ResProxy_1"
collected: destruct c
3
--- cycle through stored invocation ---
string(6) "stored"
collected: destruct d
6
--- weak reference to proxy ---
bool(true)
NULL
--- many proxies / class reuse ---
int(1)
string(7) "HI N199"
destruct n0
destruct n1
destruct n2
destruct n3
destruct n4
destruct n5
destruct n6
destruct n7
destruct n8
destruct n9
destruct n10
destruct n11
destruct n12
destruct n13
destruct n14
destruct n15
destruct n16
destruct n17
destruct n18
destruct n19
destruct n20
destruct n21
destruct n22
destruct n23
destruct n24
destruct n25
destruct n26
destruct n27
destruct n28
destruct n29
destruct n30
destruct n31
destruct n32
destruct n33
destruct n34
destruct n35
destruct n36
destruct n37
destruct n38
destruct n39
destruct n40
destruct n41
destruct n42
destruct n43
destruct n44
destruct n45
destruct n46
destruct n47
destruct n48
destruct n49
destruct n50
destruct n51
destruct n52
destruct n53
destruct n54
destruct n55
destruct n56
destruct n57
destruct n58
destruct n59
destruct n60
destruct n61
destruct n62
destruct n63
destruct n64
destruct n65
destruct n66
destruct n67
destruct n68
destruct n69
destruct n70
destruct n71
destruct n72
destruct n73
destruct n74
destruct n75
destruct n76
destruct n77
destruct n78
destruct n79
destruct n80
destruct n81
destruct n82
destruct n83
destruct n84
destruct n85
destruct n86
destruct n87
destruct n88
destruct n89
destruct n90
destruct n91
destruct n92
destruct n93
destruct n94
destruct n95
destruct n96
destruct n97
destruct n98
destruct n99
destruct n100
destruct n101
destruct n102
destruct n103
destruct n104
destruct n105
destruct n106
destruct n107
destruct n108
destruct n109
destruct n110
destruct n111
destruct n112
destruct n113
destruct n114
destruct n115
destruct n116
destruct n117
destruct n118
destruct n119
destruct n120
destruct n121
destruct n122
destruct n123
destruct n124
destruct n125
destruct n126
destruct n127
destruct n128
destruct n129
destruct n130
destruct n131
destruct n132
destruct n133
destruct n134
destruct n135
destruct n136
destruct n137
destruct n138
destruct n139
destruct n140
destruct n141
destruct n142
destruct n143
destruct n144
destruct n145
destruct n146
destruct n147
destruct n148
destruct n149
destruct n150
destruct n151
destruct n152
destruct n153
destruct n154
destruct n155
destruct n156
destruct n157
destruct n158
destruct n159
destruct n160
destruct n161
destruct n162
destruct n163
destruct n164
destruct n165
destruct n166
destruct n167
destruct n168
destruct n169
destruct n170
destruct n171
destruct n172
destruct n173
destruct n174
destruct n175
destruct n176
destruct n177
destruct n178
destruct n179
destruct n180
destruct n181
destruct n182
destruct n183
destruct n184
destruct n185
destruct n186
destruct n187
destruct n188
destruct n189
destruct n190
destruct n191
destruct n192
destruct n193
destruct n194
destruct n195
destruct n196
destruct n197
destruct n198
destruct n199
destroyed=204
--- mock has no destructor semantics ---
destroyed=204
--- interface mock cycle via property interceptor ---
string(3) "ran"
collected: %d
done
