<?php

declare(strict_types=1);

namespace Gacela\Container;

use Closure;
use Gacela\Container\Exception\CircularDependencyException;
use Gacela\Container\Exception\DependencyNotFoundException;
use WeakReference;

use function array_keys;
use function array_values;
use function class_exists;
use function interface_exists;
use function is_array;
use function is_callable;
use function is_object;
use function is_string;
use function spl_object_id;

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

    /** @var array<string, true> */
    private array $resolvingBindings = [];

    /** @var WeakReference<ContainerInterface>|null */
    private readonly ?WeakReference $ownerRef;

    /**
     * @param BindingsMap $bindings
     * @param WeakReference<ContainerInterface>|null $containerRef weak on purpose; see Container::__construct()
     * @param (Closure(string): array{string, bool})|null $registrationInfo
     */
    public function __construct(
        private array &$bindings = [],
        private ?WeakReference $containerRef = null,
        private ?Closure $registrationInfo = null,
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

            if (is_string($binding)) {
                if (class_exists($binding)) {
                    return $cacheManager->instantiate($binding);
                }

                if (interface_exists($binding)) {
                    return $this->resolveInterface($class, $binding, $cacheManager);
                }

                throw DependencyNotFoundException::unresolvableId($binding);
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
     * Resolve a type name to its concrete implementation without constructing it.
     *
     * @param class-string $typeName
     *
     * @return class-string
     */
    public function resolveType(string $typeName): string
    {
        return $this->resolveTypeChain($typeName, [], $typeName);
    }

    private function resolveInterface(string $class, string $target, DependencyCacheManager $cacheManager): mixed
    {
        $alreadyResolving = isset($this->resolvingBindings[$class]);
        if ($alreadyResolving && !$cacheManager->canReuse($class)) {
            throw CircularDependencyException::create([...array_keys($this->resolvingBindings), $class]);
        }

        $this->resolvingBindings[$class] = true;

        try {
            $owner = $this->containerRef?->get() ?? $this->ownerRef?->get();
            if ($owner !== null) {
                if (!$owner->has($target)) {
                    throw DependencyNotFoundException::unresolvableId($target);
                }

                return $owner->get($target);
            }

            if (!isset($this->bindings[$target])) {
                throw DependencyNotFoundException::unresolvableId($target);
            }

            return $this->resolve($target, $cacheManager);
        } finally {
            if (!$alreadyResolving) {
                unset($this->resolvingBindings[$class]);
            }
        }
    }

    /**
     * @param array<string, string> $seen
     * @param class-string $fallback
     *
     * @return class-string
     */
    private function resolveTypeChain(string $id, array $seen, string $fallback): string
    {
        $stored = false;
        if ($this->registrationInfo !== null) {
            [$id, $stored] = ($this->registrationInfo)($id);
        }

        if (class_exists($id) || interface_exists($id)) {
            $fallback = $id;
        }

        if ($stored && !isset($this->bindings[$id])) {
            return $fallback;
        }

        if (!isset($this->bindings[$id])) {
            return $this->parent?->resolveTypeChain($id, $seen, $fallback) ?? $fallback;
        }

        $binding = $this->bindings[$id];
        if (!is_string($binding)) {
            return $fallback;
        }

        if (class_exists($binding)) {
            return $binding;
        }

        if (!interface_exists($binding)) {
            return $fallback;
        }

        $key = spl_object_id($this) . ':' . $id;
        if (isset($seen[$key])) {
            throw CircularDependencyException::create([...array_values($seen), $id]);
        }

        $seen[$key] = $id;

        return $this->resolveTypeChain($binding, $seen, $binding);
    }
}
