<?php

/** @generate-class-entries */

namespace Proxy {
    /**
     * Factory and introspection entry point for engine-level proxy objects.
     *
     * @strict-properties
     * @not-serializable
     */
    final class Proxy
    {
        private function __construct() {}

        /**
         * Creates a strict proxy without a backing object.
         *
         * @template T of object
         * @phpstan-param class-string<T> $class
         * @psalm-param class-string<T> $class
         * @param null|callable(Invocation):mixed $methodInterceptor
         * @param null|callable(PropertyInvocation):mixed $propertyInterceptor
         * @return T
         */
        public static function mock(
            string $class,
            ?callable $methodInterceptor = null,
            ?callable $propertyInterceptor = null,
        ): object {}

        /**
         * Creates a proxy around an existing object.
         *
         * @template T of object
         * @param T $target
         * @param null|callable(Invocation):mixed $methodInterceptor
         * @param null|callable(PropertyInvocation):mixed $propertyInterceptor
         * @return T
         */
        public static function wrap(
            object $target,
            ?callable $methodInterceptor = null,
            ?callable $propertyInterceptor = null,
        ): object {}

        public static function isProxy(object $object): bool {}

        public static function target(object $proxy): ?object {}
    }


    /**
     * Describes an intercepted method call and allows calling the target.
     *
     * @strict-properties
     * @not-serializable
     */
    final class Invocation
    {
        private function __construct() {}

        public function method(): string {}

        public function args(): array {}

        public function arg(int $index): mixed {}

        public function proxy(): object {}

        public function target(): ?object {}

        public function class(): string {}

        public function hasOriginal(): bool {}

        /** @prefer-ref $args */
        public function &proceed(mixed ...$args): mixed {}
    }

    /**
     * Describes an intercepted property operation and allows accessing the target.
     *
     * @strict-properties
     * @not-serializable
     */
    final class PropertyInvocation
    {
        private function __construct() {}

        public function property(): string {}

        public function operation(): PropertyOperation {}

        public function value(): mixed {}

        public function proxy(): object {}

        public function target(): ?object {}

        public function class(): string {}

        public function hasOriginal(): bool {}

        public function &proceed(mixed ...$args): mixed {}
    }

    enum PropertyOperation
    {
        case GET;
        case SET;
        case ISSET;
        case UNSET;
    }
}

namespace Proxy\Exception {
    class ProxyException extends \Exception {}

    class UnconfiguredMethod extends ProxyException {}

    class UnconfiguredProperty extends ProxyException {}

    class NoOriginalImplementation extends ProxyException {}

    class InvalidProxyTarget extends ProxyException {}

    class InvalidInterceptor extends ProxyException {}

    class UnsupportedProxyType extends ProxyException {}

    class UnsupportedOperation extends ProxyException {}
}
