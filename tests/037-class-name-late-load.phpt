--TEST--
Proxy rejects dl() loading after source compilation instead of partially changing class-name semantics
--EXTENSIONS--
proxy
--SKIPIF--
<?php
if (!function_exists('proc_open')) {
    die('skip proc_open is unavailable');
}
if (!is_file(dirname(__DIR__) . '/modules/proxy.' . PHP_SHLIB_SUFFIX)) {
    die('skip an in-tree proxy module is required by the subprocess test');
}
?>
--FILE--
<?php
$source = <<<'PHP'
echo 'before:', (new stdClass)::class, "\n";
dl('proxy.' . PHP_SHLIB_SUFFIX);
echo "unreachable after late loading\n";
PHP;
$command = [PHP_BINARY, '-n', '-d', 'enable_dl=1', '-d', 'display_errors=1',
    '-d', 'log_errors=0', '-d', 'extension_dir=' . dirname(__DIR__) . '/modules',
    '-r', $source];
$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Unable to start PHP subprocess');
}
fclose($pipes[0]);
$output = stream_get_contents($pipes[1]);
$output .= stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$status = proc_close($process);
var_dump(str_starts_with($output, "before:stdClass\n"));
var_dump(str_contains(
    $output,
    'proxy must be loaded at PHP startup; dl() cannot update already compiled ::class expressions'
));
var_dump(str_contains($output, 'Unable to start proxy module'));
var_dump($status === 255);
var_dump(!str_contains($output, 'unreachable after late loading'));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
