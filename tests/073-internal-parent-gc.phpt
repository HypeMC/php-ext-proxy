--TEST--
Internal parent GC handlers preserve extension-owned edges and collect interceptor cycles
--EXTENSIONS--
proxy
--FILE--
<?php
use Proxy\Proxy;

foreach ([stdClass::class, ArrayObject::class, SplStack::class, SplQueue::class,
    SplDoublyLinkedList::class, SplObjectStorage::class] as $class) {
    foreach (['method', 'property', 'both'] as $mode) {
        $callback = function ($call) use (&$objectProxy) {
            return $objectProxy;
        };
        $options = match ($mode) {
            'method' => ['methodInterceptor' => $callback],
            'property' => ['propertyInterceptor' => $callback],
            'both' => ['methodInterceptor' => $callback, 'propertyInterceptor' => $callback],
        };
        $objectProxy = Proxy::wrap(new $class, ...$options);
        $weakProxy = WeakReference::create($objectProxy);
        $weakTarget = WeakReference::create(Proxy::target($objectProxy));
        unset($objectProxy, $callback, $options);
        gc_collect_cycles();
        echo "$class $mode: ",
            $weakProxy->get() === null && $weakTarget->get() === null ? 'collected' : 'leaked', "\n";
    }
}
?>
--EXPECT--
stdClass method: collected
stdClass property: collected
stdClass both: collected
ArrayObject method: collected
ArrayObject property: collected
ArrayObject both: collected
SplStack method: collected
SplStack property: collected
SplStack both: collected
SplQueue method: collected
SplQueue property: collected
SplQueue both: collected
SplDoublyLinkedList method: collected
SplDoublyLinkedList property: collected
SplDoublyLinkedList both: collected
SplObjectStorage method: collected
SplObjectStorage property: collected
SplObjectStorage both: collected
