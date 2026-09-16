--TEST--
Dynamic proxy class-name expressions retain their semantics in hot tracing-JIT code
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
if (!function_exists('opcache_get_status') || ini_get('opcache.jit') === false) {
    die('skip PHP was built without OPcache/JIT support');
}
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
?>
--FILE--
<?php
use Proxy\Proxy;

final class JitClassNameTarget
{
}
interface JitClassNameContract
{
}
class JitClassNameOther
{
}

function exerciseClassNames(array $objects, array $expected): bool
{
    for ($i = 0; $i < 1000; ++$i) {
        foreach ($objects as $index => $object) {
            if ($object::class !== $expected[$index]) {
                return false;
            }
            if ([$object][0]::class !== $expected[$index]) {
                return false;
            }
        }
    }
    return true;
}

$jit = opcache_get_status(false)['jit'];
var_dump($jit['enabled'], $jit['on']);
$before = $jit['buffer_free'];
$target = new JitClassNameTarget;
$objects = [Proxy::wrap($target), Proxy::mock(JitClassNameTarget::class),
    $target, Proxy::mock(JitClassNameContract::class), new JitClassNameOther];
$expected = [JitClassNameTarget::class, JitClassNameTarget::class,
    JitClassNameTarget::class, JitClassNameContract::class, JitClassNameOther::class];
var_dump(exerciseClassNames($objects, $expected));
// Reuse compiled code after changing which types and proxies reach each slot.
var_dump(exerciseClassNames(array_reverse($objects), array_reverse($expected)));
var_dump(get_class($objects[0]) !== $objects[0]::class);
$jit = opcache_get_status(false)['jit'];
var_dump($jit['enabled'], $jit['on'], $jit['buffer_free'] < $before);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
