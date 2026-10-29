<?php

declare(strict_types=1);

namespace Gacela\Container;

use Closure;
use WeakReference;

use function class_exists;
use function is_array;
use function is_callable;
use function is_object;
use function is_string;

/**
 * Resolves abstract types to concrete implementations using bindings.
 *
 * @psalm-import-type Binding from ContainerInterface
 * @psalm-import-type BindingsMap from ContainerInterface
 *
 * @internal
 * Not covered by backward compatibility: this class is an implementation
 * detail of Container and may change or disappear in any release
 */
final class BindingResolver
{
    private ?self $parent = null;

    /** @var WeakReference<ContainerInterface>|null */
    private readonly ?WeakReference $ownerRef;

    /**
     * @param BindingsMap $bindings
     * @param WeakReference<ContainerInterface>|null $containerRef weak on purpose; see Container::__construct()
     */
    public function __construct(
        private array &$bindings = [],
        private ?WeakReference $containerRef = null,
    ) {
        $this->ownerRef = $containerRef;
    }

    /**
     * Change what a closure binding is handed. See
     * Container::withSelfReference().
     *
     * @param WeakReference<ContainerInterface> $containerRef
     */
    public function useSelfReference(WeakReference $containerRef): void
    {
        $this->containerRef = $containerRef;
    }

    /**
     * Let a scope read the bindings of the container it was created from.
     */
    public function inheritFrom(self $parent): void
    {
        $this->parent = $parent;
    }

    public function resolve(string $class, DependencyCacheManager $cacheManager): mixed
    {
        if (isset($this->bindings[$class])) {
            /** @var mixed $binding */
            $binding = $this->bindings[$class];

            if ($binding instanceof Closure || (is_array($binding) && is_callable($binding))) {
                // Calling a factory here is eager; lazy() defers it.
                /** @var class-string $class */
                $lazyProxy = $cacheManager->lazyProxyFor($class);
                if ($lazyProxy !== null) {
                    return $lazyProxy;
                }

                return $binding($this->containerRef?->get() ?? $this->ownerRef?->get());
            }

            if (is_object($binding)) {
                return $binding;
            }

            if (is_string($binding) && class_exists($binding)) {
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
        $binding = $this->bindings[$typeName] ?? $this->parent?->findBinding($typeName);

        if (is_string($binding) && class_exists($binding)) {
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
     *
     * @return Binding|null
     */
    private function findBinding(string $typeName): mixed
    {
        return $this->bindings[$typeName] ?? $this->parent?->findBinding($typeName);
    }
}
