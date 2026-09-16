--TEST--
Proxy interception preserves tracing JIT for final methods and Invocation-only middleware
--EXTENSIONS--
opcache
proxy
--INI--
opcache.enable=1
opcache.enable_cli=1
opcache.file_update_protection=0
opcache.jit=tracing
opcache.jit_buffer_size=16M
opcache.jit_hot_func=2
opcache.jit_hot_loop=2
--SKIPIF--
<?php
if (!function_exists('opcache_get_status')) {
    die('skip OPcache is unavailable');
}
if (ini_get('opcache.jit') === false) {
    die('skip PHP was built without JIT support');
}
// Extensions that override zend_execute_ex() make OPcache disable the JIT
// before any test code runs; that is not a proxy regression.
foreach ([
    'xdebug',
    'pcov',
    'blackfire',
    'tideways',
    'tideways_xhprof',
    'uopz',
    'runkit7',
    'newrelic',
    'ddtrace'
] as $incompatible) {
    if (extension_loaded($incompatible)) {
        die("skip $incompatible disables the JIT");
    }
}
// Do not skip on jit.enabled/on beyond that: disabling JIT in the extension is a regression.
?>
--FILE--
<?php
use Proxy\Invocation;
use Proxy\Proxy;

$jit = opcache_get_status(false)['jit'];
var_dump($jit['enabled'], $jit['on']);
$bufferBefore = $jit['buffer_free'];

final class JitFinalTarget
{
    public int $calls = 0;

    final public function calculate(int $value): int
    {
        ++$this->calls;
        return $value + 1;
    }
}

function exercise(JitFinalTarget $object): int
{
    $sum = 0;
    for ($i = 0; $i < 200; ++$i) {
        $sum += $object->calculate($i);
    }
    return $sum;
}

$interceptorCalls = 0;
$target = new JitFinalTarget();
$wrapped = Proxy::wrap(
    $target,
    methodInterceptor: function (Invocation $call) use (&$interceptorCalls): int {
        ++$interceptorCalls;
        $args = $call->args();
        $args[0] *= 2;
        return $call->proceed(...$args);
    },
);
var_dump(exercise($wrapped), $interceptorCalls, $target->calls);

$interceptorCalls = 0;
$mock = Proxy::mock(
    JitFinalTarget::class,
    methodInterceptor: function (Invocation $call) use (&$interceptorCalls): int {
        ++$interceptorCalls;
        return $call->args()[0] * 3;
    },
);
var_dump(exercise($mock), $interceptorCalls);

$jit = opcache_get_status(false)['jit'];
var_dump($jit['enabled'], $jit['on']);
// The hot loop calling through the proxy was actually compiled: the JIT
// consumed buffer space. Configuration flags alone would not show a bail-out.
var_dump($jit['buffer_free'] < $bufferBefore);
?>
--EXPECT--
bool(true)
bool(true)
int(40000)
int(200)
int(200)
int(59700)
int(200)
bool(true)
bool(true)
bool(true)
