--TEST--
Property reference returns preserve source types and native coercion semantics
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;

class ReferenceSource {
    public string $text = '12';
    public int $integer = 12;
    public int|float $number = 12;
    public $untyped = '12';
}

class NativeReferenceView {
    public function __construct(public ReferenceSource $source) {
    }

    public int $fromText { &get => $this->source->text; }
    public float $fromInteger { &get => $this->source->integer; }
    public float $fromNumber { &get => $this->source->number; }
    public int $matching { &get => $this->source->integer; }
    public int|string $matchingUnion { &get => $this->source->text; }
    public int $fromUntyped { &get => $this->source->untyped; }
}

class InterceptedReferenceView {
    public int $fromText = 0;
    public float $fromInteger = 0.0;
    public float $fromNumber = 0.0;
    public int $matching = 0;
    public int|string $matchingUnion = 0;
    public int $fromUntyped = 0;
}

function check(bool $condition): void {
    if (!$condition) {
        throw new RuntimeException('Property reference contract changed');
    }
}

function expectTypeError(Closure $operation): void {
    try {
        $operation();
    } catch (TypeError) {
        return;
    }
    throw new RuntimeException('Missing reference type check');
}

foreach (['native', 'proxy'] as $mode) {
    $source = new ReferenceSource;
    if ($mode === 'native') {
        $view = new NativeReferenceView($source);
    } else {
        $target = new InterceptedReferenceView;
        $view = Proxy::wrap($target, propertyInterceptor: function &($call) use ($source) {
            switch ($call->property()) {
                case 'fromText':
                case 'matchingUnion':
                    return $source->text;
                case 'fromInteger':
                case 'matching':
                    return $source->integer;
                case 'fromNumber':
                    return $source->number;
                case 'fromUntyped':
                    return $source->untyped;
                default:
                    return $call->proceed(...($call->operation() === \Proxy\PropertyOperation::SET
                        ? [$call->value()] : []));
            }
        });
    }
    foreach (['fromText', 'fromInteger', 'fromNumber'] as $property) {
        expectTypeError(function () use ($view, $property) {
            $value = $view->$property;
        });
        expectTypeError(function () use ($view, $property) {
            $value =& $view->$property;
        });
    }
    check($source->text === '12' && $source->integer === 12 && $source->number === 12);
    echo "$mode: typed reference conversions rejected\n";

    $reference =& $view->matching;
    $reference = 24;
    check($source->integer === 24);
    expectTypeError(function () use (&$reference) {
        $reference = 'invalid';
    });
    unset($reference);
    $reference =& $view->matchingUnion;
    $reference = 'changed';
    check($source->text === 'changed');
    expectTypeError(function () use (&$reference) {
        $reference = [];
    });
    unset($reference);
    echo "$mode: compatible references retain aliases and source types\n";

    $reference =& $view->fromUntyped;
    check($reference === 12 && $source->untyped === 12);
    $reference = 30;
    check($source->untyped === 30);
    unset($reference, $view, $source, $target);
    echo "$mode: untyped references allow native weak coercion\n";
}

foreach (['native', 'proxy'] as $mode) {
    $source = new ReferenceSource;
    $target = new InterceptedReferenceView;
    $view = $mode === 'native' ? $target : Proxy::wrap(
        $target,
        propertyInterceptor: fn ($call) => $call->proceed($call->value()),
    );
    $reference =& $source->text;
    $view->fromText = $reference;
    check($target->fromText === 12 && $source->text === '12');
    unset($reference, $view, $source, $target);
    echo "$mode: assignment coercion leaves its referenced input unchanged\n";
}
?>
--EXPECT--
native: typed reference conversions rejected
native: compatible references retain aliases and source types
native: untyped references allow native weak coercion
proxy: typed reference conversions rejected
proxy: compatible references retain aliases and source types
proxy: untyped references allow native weak coercion
native: assignment coercion leaves its referenced input unchanged
proxy: assignment coercion leaves its referenced input unchanged
