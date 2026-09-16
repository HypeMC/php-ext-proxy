--TEST--
Invocation teardown clears weak references before releasing captured values or the proxy
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Invocation;
use Proxy\PropertyInvocation;
use Proxy\Proxy;

class InvocationSubject
{
    public object $value;

    public function accept(object $value): void {}
}

function observeInvocationRelease(string $label): void
{
    // Retaining a dying invocation used to leave a reference to freed memory.
    $GLOBALS['retained'] = $GLOBALS['weak']->get();
    echo $label, ': ', $GLOBALS['retained'] === null ? 'null' : 'live',
        ', weak map entries: ', count($GLOBALS['map']), "\n";
}

class CapturedValue
{
    public function __construct(private string $label) {}

    public function __destruct()
    {
        observeInvocationRelease($this->label);
    }
}

foreach (['method', 'property'] as $operation) {
    $invocation = null;
    $capture = function (Invocation|PropertyInvocation $call) use (&$invocation): void {
        $invocation = $call;
    };
    $proxy = Proxy::mock(
        InvocationSubject::class,
        methodInterceptor: $capture,
        propertyInterceptor: $capture,
    );
    if ($operation === 'method') {
        $proxy->accept(new CapturedValue('captured method argument'));
    } else {
        $proxy->value = new CapturedValue('captured SET value');
    }

    $weak = WeakReference::create($invocation);
    $map = new WeakMap;
    $map[$invocation] = 'observed';
    $invocation = null;
    unset($retained, $proxy, $capture);
}

class OwnedTarget extends InvocationSubject
{
    public function __destruct()
    {
        observeInvocationRelease('owned target');
    }
}

// An invocation can be the sole owner of its proxy, which in turn owns the target.
$invocation = null;
$proxy = Proxy::wrap(new OwnedTarget, methodInterceptor: function (Invocation $call) use (&$invocation): void {
    $invocation = $call;
});
$proxy->accept(new stdClass);
$weak = WeakReference::create($invocation);
$map = new WeakMap;
$map[$invocation] = 'observed';
unset($proxy);
$invocation = null;
unset($retained);
echo "done\n";
?>
--EXPECT--
captured method argument: null, weak map entries: 0
captured SET value: null, weak map entries: 0
owned target: null, weak map entries: 0
done
