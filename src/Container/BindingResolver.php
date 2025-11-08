<?php

declare(strict_types=1);

namespace Gacela\Container;

use function class_exists;
use function is_callable;
use function is_object;
use function is_string;

/**
 * Resolves abstract types to concrete implementations using bindings.
 */
final class BindingResolver
{
    /**
     * @param array<class-string, class-string|callable|object> $bindings
     */
    public function __construct(
        private array $bindings = [],
    ) {
    }

    /**
     * Resolve a class to a concrete instance using bindings.
     */
    public function resolve(string $class, DependencyCacheManager $cacheManager): ?object
    {
        if (isset($this->bindings[$class])) {
            $binding = $this->bindings[$class];

            if (is_callable($binding)) {
                /** @var mixed $binding */
                $binding = $binding();
            }

            if (is_object($binding)) {
                return $binding;
            }

            /** @var class-string $binding */
            if (class_exists($binding)) {
                return $cacheManager->instantiate($binding);
            }
        }

        if (class_exists($class)) {
            return $cacheManager->instantiate($class);
        }

        return null;
    }

    /**
     * Check if a binding exists for a class.
     */
    public function hasBinding(string $class): bool
    {
        return isset($this->bindings[$class]);
    }

    /**
     * Get all bindings.
     *
     * @return array<class-string, class-string|callable|object>
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Resolve a type name to its concrete implementation (for dependency analysis).
     *
     * @param class-string $typeName
     *
     * @return class-string
     */
    public function resolveType(string $typeName): string
    {
        if (isset($this->bindings[$typeName])) {
            $binding = $this->bindings[$typeName];

            if (is_string($binding) && class_exists($binding)) {
                /** @var class-string */
                return $binding;
            }
        }

        return $typeName;
    }
}
