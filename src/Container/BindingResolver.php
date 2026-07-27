<?php

declare(strict_types=1);

namespace Gacela\Container;

use function class_exists;
use function is_callable;
use function is_object;
use function is_string;

/**
 * Resolves abstract types to concrete implementations using bindings.
 *
 * @psalm-import-type BindingsMap from ContainerInterface
 *
 * @internal
 * Not covered by backward compatibility: this class is an implementation
 * detail of Container and may change or disappear in any release
 */
final class BindingResolver
{
    private ?self $parent = null;

    /**
     * @param BindingsMap $bindings
     */
    public function __construct(
        private array &$bindings = [],
        private ?ContainerInterface $container = null,
    ) {
    }

    /**
     * Let a scope read the bindings of the container it was created from.
     */
    public function inheritFrom(self $parent): void
    {
        $this->parent = $parent;
    }

    public function resolve(string $class, DependencyCacheManager $cacheManager): ?object
    {
        if (isset($this->bindings[$class])) {
            $binding = $this->bindings[$class];

            if (is_callable($binding)) {
                /** @var mixed $binding */
                $binding = $binding($this->container);
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
     * @return BindingsMap
     */
    public function getBindings(): array
    {
        if ($this->parent === null) {
            return $this->bindings;
        }

        // Union keeps the left operand on a duplicate key: a scope shadows what
        // it inherits.
        return $this->bindings + $this->parent->getBindings();
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
        /** @psalm-suppress MixedAssignment */
        $binding = $this->findBinding($typeName);

        if (is_string($binding) && class_exists($binding)) {
            /** @var class-string */
            return $binding;
        }

        return $typeName;
    }

    /**
     * The binding for one id, from the nearest container in the chain that has
     * one.
     *
     * Deliberately not `getBindings()[$id]`: this runs once per constructor
     * parameter of every node of a dependency tree, and building the merged map
     * each time made analysing a scope scale with the size of its parent.
     */
    private function findBinding(string $typeName): mixed
    {
        return $this->bindings[$typeName] ?? $this->parent?->findBinding($typeName);
    }
}
