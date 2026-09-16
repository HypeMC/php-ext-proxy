--TEST--
Compiled proxy class-name expressions survive disk-cache reuse and are isolated from PHP without proxy
--EXTENSIONS--
opcache
proxy
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80500) {
    die('skip this subprocess test requires PHP 8.5 with built-in OPcache');
}
if (!function_exists('proc_open')) {
    die('skip proc_open is unavailable');
}
if (!is_file(dirname(__DIR__) . '/modules/proxy.' . PHP_SHLIB_SUFFIX)) {
    die('skip an in-tree proxy module is required by the subprocess test');
}
?>
--FILE--
<?php
function removeClassNameCacheDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            removeClassNameCacheDirectory($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($directory);
}

function classNameCacheFiles(string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $directory,
        FilesystemIterator::SKIP_DOTS
    ));
    foreach ($iterator as $entry) {
        if ($entry->isFile()) {
            $files[] = $entry->getPathname();
        }
    }
    sort($files);
    return $files;
}

function classNameCachePartitions(string $directory): array
{
    $names = [];
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
        if ($entry->isDir()) {
            $names[] = $entry->getFilename();
        }
    }
    sort($names);
    return $names;
}

function runClassNameCacheProbe(string $script, string $cache, bool $withProxy): string
{
    $command = [PHP_BINARY, '-n',
        '-d', 'opcache.enable_cli=1',
        '-d', 'opcache.file_update_protection=0',
        '-d', 'opcache.file_cache_only=1',
        '-d', 'opcache.file_cache=' . $cache,
    ];
    if ($withProxy) {
        $command[] = '-d';
        $command[] = 'extension=' . dirname(__DIR__) . '/modules/proxy.' . PHP_SHLIB_SUFFIX;
    }
    $command[] = $script;
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start PHP subprocess');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 || $errors !== '') {
        throw new RuntimeException("PHP subprocess failed ($status): $errors$output");
    }
    return $output;
}

$directory = tempnam(sys_get_temp_dir(), 'proxy-class-cache-');
unlink($directory);
mkdir($directory, 0700);
try {
    $cache = $directory . '/cache';
    mkdir($cache, 0700);
    $script = $directory . '/probe.php';
    file_put_contents($script, <<<'PHP'
<?php
class CacheClassNameTarget {}
$ordinary = new CacheClassNameTarget;
echo 'ordinary:', $ordinary::class, "\n";
if (extension_loaded('proxy')) {
    $proxy = Proxy\Proxy::mock(CacheClassNameTarget::class);
    echo 'proxy:', $proxy::class, "\n";
} else {
    echo "proxy:absent\n";
}
PHP);
    $expected = "ordinary:CacheClassNameTarget\nproxy:CacheClassNameTarget\n";
    echo 'initial compile: ', runClassNameCacheProbe($script, $cache, true) === $expected ? 'ok' : 'FAIL', "\n";
    $files = classNameCacheFiles($cache);
    $partitions = classNameCachePartitions($cache);
    echo 'cache populated: ', count($files) > 0 && count($partitions) === 1 ? 'ok' : 'FAIL', "\n";

    // Successful cache loading leaves the existing cache file intact. Moving
    // its filesystem timestamp makes a rewrite distinguishable even when both
    // child processes finish within the same second.
    $oldTime = time() - 120;
    foreach ($files as $file) {
        touch($file, $oldTime);
    }
    echo 'cached execution: ', runClassNameCacheProbe($script, $cache, true) === $expected ? 'ok' : 'FAIL', "\n";
    clearstatcache();
    $unchanged = classNameCacheFiles($cache) === $files;
    foreach ($files as $file) {
        $unchanged = $unchanged && filemtime($file) === $oldTime;
    }
    echo 'cache reused without rewrite: ', $unchanged ? 'ok' : 'FAIL', "\n";

    // Without the extension, PHP must never load cached helper calls emitted
    // by proxy. The same source remains ordinary valid PHP in this process.
    $expected = "ordinary:CacheClassNameTarget\nproxy:absent\n";
    echo 'extension absent: ', runClassNameCacheProbe($script, $cache, false) === $expected ? 'ok' : 'FAIL', "\n";
    $withoutProxy = classNameCachePartitions($cache);
    echo 'separate cache identity: ', count($withoutProxy) === 2
        && count(array_diff($withoutProxy, $partitions)) === 1 ? 'ok' : 'FAIL', "\n";
    echo 'both caches populated: ', count(classNameCacheFiles($cache)) > count($files) ? 'ok' : 'FAIL', "\n";
} finally {
    removeClassNameCacheDirectory($directory);
}
?>
--EXPECT--
initial compile: ok
cache populated: ok
cached execution: ok
cache reused without rewrite: ok
extension absent: ok
separate cache identity: ok
both caches populated: ok
