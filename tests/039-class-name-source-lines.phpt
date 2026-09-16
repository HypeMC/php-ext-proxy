--TEST--
Generated class-name calls retain source lines inside their functions with Xdebug enabled
--EXTENSIONS--
proxy
xdebug
--ENV--
XDEBUG_MODE=debug,coverage
--INI--
xdebug.mode=debug,coverage
xdebug.start_with_request=no
xdebug.log_level=0
opcache.jit=off
opcache.jit_buffer_size=0
--FILE--
<?php
use Proxy\Proxy;

class SourceLineTarget {}

$source = <<<'PHP'
<?php
function sourceLineClassName(object $object): string
{
    return $object::class;
}
final class SourceLineReader
{
    public function read(object $object): string
    {
        return $object::class;
    }
}
return [
    new SourceLineReader,
    static fn (object $object): string => $object::class,
    static function (object $object): string {
        return $object::class;
    },
];
PHP;

$expressionLines = [];
foreach (explode("\n", $source) as $index => $line) {
    if (str_contains($line, '$object::class')) {
        $expressionLines[] = $index + 1;
    }
}
$lastCodeLine = substr_count($source, "\n") + 1;
// The AST hook runs after parsing this padding. Injected call nodes must use
// their original expression's line, never the parser's end-of-file line.
$source .= "\n" . str_repeat("// Non-executable trailing source.\n", 128);
$fixture = tempnam(sys_get_temp_dir(), 'proxy-source-lines-');
file_put_contents($fixture, $source);

try {
    xdebug_start_code_coverage();
    [$reader, $arrow, $closure] = include $fixture;
    $namesMatch = true;
    foreach ([new SourceLineTarget, Proxy::wrap(new SourceLineTarget), Proxy::mock(SourceLineTarget::class)] as $object) {
        foreach ([sourceLineClassName(...), $reader->read(...), $arrow, $closure] as $read) {
            $namesMatch = $namesMatch && $read($object) === SourceLineTarget::class;
        }
    }
    $coverage = xdebug_get_code_coverage()[$fixture] ?? [];
    xdebug_stop_code_coverage();

    $executedLines = array_keys(array_filter($coverage, static fn (int $status): bool => $status === 1));
    $linesMatch = $executedLines !== [] && max($executedLines) <= $lastCodeLine;
    foreach ($expressionLines as $line) {
        $linesMatch = $linesMatch && ($coverage[$line] ?? null) === 1;
    }
    echo 'native, wrapped and mocked names: ', $namesMatch ? 'ok' : 'FAIL', "\n";
    echo 'executed lines stay in original source: ', $linesMatch ? 'ok' : 'FAIL', "\n";
} finally {
    if (xdebug_code_coverage_started()) {
        xdebug_stop_code_coverage();
    }
    unlink($fixture);
}
?>
--EXPECT--
native, wrapped and mocked names: ok
executed lines stay in original source: ok
