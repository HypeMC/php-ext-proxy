--TEST--
Dynamic proxy ::class names expose the proxied type while native runtime identity remains intact
--EXTENSIONS--
proxy
--FILE--
<?php
namespace ClassNameTests;

use Proxy\Proxy;
use ReflectionClass;
use ReflectionObject;
use Throwable;

function check(string $label, mixed $actual, mixed $expected): void
{
    echo $label, ': ', $actual === $expected ? 'ok' : 'FAIL ' . var_export($actual, true), "\n";
}

class ParentType
{
}
final class Service extends ParentType
{
    public int $value = 1;

    public const LABEL = 'native constant';

    public static function literalNames(): array
    {
        return [self::class, parent::class, static::class];
    }

    public function objectName(object $object): string
    {
        return $object::class;
    }
}
abstract class AbstractService
{
    abstract public function run(): void;
}
interface ServiceContract
{
    public function run(): void;
}
class OrdinaryService
{
}
readonly class ReadonlyService
{
}
class OrdinaryServiceProxy_999
{
}

$target = new Service;
$wrapped = Proxy::wrap($target);
$mock = Proxy::mock(Service::class);
$abstract = Proxy::mock(AbstractService::class);
$interface = Proxy::mock(ServiceContract::class);
$ordinary = Proxy::mock(OrdinaryService::class);
$readonly = Proxy::mock(ReadonlyService::class);
check('wrapped final', $wrapped::class, Service::class);
check('mocked final', $mock::class, Service::class);
check('uppercase class keyword', $wrapped::CLASS, Service::class);
check('mixed-case class keyword', $wrapped::Class, Service::class);
check('mocked abstract', $abstract::class, AbstractService::class);
check('mocked interface', $interface::class, ServiceContract::class);
check('mocked ordinary', $ordinary::class, OrdinaryService::class);
check('mocked readonly', $readonly::class, ReadonlyService::class);
check('wrapped internal', Proxy::wrap(new \ArrayObject)::class, \ArrayObject::class);

$runtime = get_class($wrapped);
check('get_class remains generated', $runtime !== Service::class, true);
check('reflection remains generated', (new ReflectionObject($wrapped))->getName(), $runtime);
check(
    'generated reflection parent',
    (new ReflectionObject($wrapped))->getParentClass()->getName(),
    Service::class
);
check('classOf method removed', method_exists(Proxy::class, 'classOf'), false);
check('classOf reflection removed', (new ReflectionClass(Proxy::class))->hasMethod('classOf'), false);
check('literal class lookup', (new ReflectionClass($wrapped::class))->getName(), Service::class);
check('direct class literal', Service::class, __NAMESPACE__ . '\\Service');
check('unresolved literal', MissingClass::class, __NAMESPACE__ . '\\MissingClass');
check(
    'self parent static remain native',
    $wrapped::literalNames(),
    [
        Service::class,
        ParentType::class,
        $runtime
    ]
);
check('runtime generated literal remains native', eval('return \\' . $runtime . '::class;'), $runtime);
// Dynamic constant access is native class-level lookup, not the ::class keyword.
function literalConstantOutcome(object|string $value): string
{
    $runtimeName = is_object($value) ? get_class($value) : $value;
    try {
        $result = $value::{'class'};
    } catch (Throwable $error) {
        $result = get_class($error) . ': ' . $error->getMessage();
    }
    return str_replace($runtimeName, '<runtime>', $result);
}
check(
    'dynamic class constant literal remains native',
    literalConstantOutcome($wrapped),
    literalConstantOutcome($target)
);
$constant = 'class';
check('dynamic class constant variable remains native', $wrapped::{$constant}, $runtime);
check(
    'generated class-string dynamic constant remains native',
    literalConstantOutcome($runtime),
    literalConstantOutcome(Service::class)
);
$constant = 'LABEL';
check('dynamic ordinary constant remains native', $wrapped::{$constant}, 'native constant');

$anonymous = new class {
};
$anonymousName = get_class($anonymous);
check('wrapped anonymous full name', Proxy::wrap($anonymous)::class, $anonymousName);
check('mocked anonymous full name', Proxy::mock($anonymousName)::class, $anonymousName);
check('ordinary object', $target::class, Service::class);
check(
    'ordinary generated-name lookalike',
    (new OrdinaryServiceProxy_999)::class,
    OrdinaryServiceProxy_999::class
);

$count = 0;
$produce = function () use (&$count, $wrapped): object {
    ++$count;
    return $wrapped;
};
check('expression name', $produce()::class, Service::class);
check('expression evaluated once', $count, 1);
$reference = & $wrapped;
check('reference operand', $reference::class, Service::class);
check('method expression', $wrapped->objectName($mock), Service::class);
check('arrow function', (fn () => $mock::class)(), Service::class);
check('closure', (function () use ($mock) { return $mock::class; })(), Service::class);
check('eval', eval('return $mock::class;'), Service::class);
check('include', include __DIR__ . '/class_name_include.inc', Service::class);
$container = (object) ['item' => $wrapped];
check('property operand', $container->item::class, Service::class);
check('nullsafe object operand', $container?->item::class, Service::class);
check('array operand', [$wrapped][0]::class, Service::class);

// The mapping is a property of the generated class, including bare lazy
// objects allocated by Reflection without any proxy interceptor state.
$initialized = false;
$shell = (new ReflectionClass($wrapped))->newLazyGhost(function ($object) use (&$initialized) {
    $initialized = true;
});
check('generated lazy shell is not configured proxy', Proxy::isProxy($shell), false);
check('generated lazy shell logical name', $shell::class, Service::class);
check('generated lazy shell runtime name', get_class($shell), $runtime);
check('class name does not initialize lazy shell', $initialized, false);

function invalidClassName(mixed $value): array
{
    try {
        $line = __LINE__ + 1;
        return [$value::class];
    } catch (Throwable $error) {
        $trace = $error->getTrace();
        return [get_class($error), $error->getMessage(), $error->getLine() === $line,
            count($trace) === 1 && $trace[0]['function'] === __NAMESPACE__ . '\\invalidClassName'];
    }
}
foreach ([null, false, true, 17, 1.5, [], 'stdClass'] as $invalid) {
    $type = is_bool($invalid) ? ($invalid ? 'true' : 'false') : get_debug_type($invalid);
    check('invalid operand ' . $type, invalidClassName($invalid), [
        \TypeError::class, 'Cannot use "::class" on ' . $type, true, true,
    ]);
}
$container = null;
try {
    $container?->item::class;
} catch (Throwable $error) {
    check('nullsafe null error', $error->getMessage(), 'Cannot use "::class" on null');
}
?>
--EXPECT--
wrapped final: ok
mocked final: ok
uppercase class keyword: ok
mixed-case class keyword: ok
mocked abstract: ok
mocked interface: ok
mocked ordinary: ok
mocked readonly: ok
wrapped internal: ok
get_class remains generated: ok
reflection remains generated: ok
generated reflection parent: ok
classOf method removed: ok
classOf reflection removed: ok
literal class lookup: ok
direct class literal: ok
unresolved literal: ok
self parent static remain native: ok
runtime generated literal remains native: ok
dynamic class constant literal remains native: ok
dynamic class constant variable remains native: ok
generated class-string dynamic constant remains native: ok
dynamic ordinary constant remains native: ok
wrapped anonymous full name: ok
mocked anonymous full name: ok
ordinary object: ok
ordinary generated-name lookalike: ok
expression name: ok
expression evaluated once: ok
reference operand: ok
method expression: ok
arrow function: ok
closure: ok
eval: ok
include: ok
property operand: ok
nullsafe object operand: ok
array operand: ok
generated lazy shell is not configured proxy: ok
generated lazy shell logical name: ok
generated lazy shell runtime name: ok
class name does not initialize lazy shell: ok
invalid operand null: ok
invalid operand false: ok
invalid operand true: ok
invalid operand int: ok
invalid operand float: ok
invalid operand array: ok
invalid operand string: ok
nullsafe null error: ok
