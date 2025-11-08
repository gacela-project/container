<?php

declare(strict_types=1);

namespace Gacela\Container;

use Closure;

use function class_exists;
use function count;

/**
 * Manages dependency resolution caching for performance optimization.
 */
final class DependencyCacheManager
{
    /** @var array<class-string|string, list<mixed>> */
    private array $cachedDependencies = [];

    private ?DependencyResolver $dependencyResolver = null;

    /**
     * @param array<class-string, class-string|callable|object> $bindings
     * @param array<string, array<class-string, class-string|callable|object>> $contextualBindings
     */
    public function __construct(
        private array $bindings = [],
        private array &$contextualBindings = [],
    ) {
    }

    /**
     * Resolve dependencies for a class, using cache if available.
     *
     * @param class-string $className
     *
     * @return list<mixed>
     */
    public function resolveDependencies(string $className): array
    {
        if (!isset($this->cachedDependencies[$className])) {
            $this->cachedDependencies[$className] = $this
                ->getDependencyResolver()
                ->resolveDependencies($className);
        }

        return $this->cachedDependencies[$className];
    }

    /**
     * Resolve dependencies for a callable with a specific cache key.
     *
     * @return list<mixed>
     */
    public function resolveCallableDependencies(string $callableKey, Closure $callable): array
    {
        if (!isset($this->cachedDependencies[$callableKey])) {
            $this->cachedDependencies[$callableKey] = $this
                ->getDependencyResolver()
                ->resolveDependencies($callable);
        }

        return $this->cachedDependencies[$callableKey];
    }

    /**
     * Pre-warm the dependency cache for multiple classes.
     *
     * @param list<class-string> $classNames
     */
    public function warmUp(array $classNames): void
    {
        foreach ($classNames as $className) {
            if (!class_exists($className)) {
                continue;
            }

            // Pre-resolve dependencies to populate cache
            if (!isset($this->cachedDependencies[$className])) {
                $this->cachedDependencies[$className] = $this
                    ->getDependencyResolver()
                    ->resolveDependencies($className);
            }
        }
    }

    /**
     * Instantiate a class using cached dependencies.
     *
     * @param class-string $class
     */
    public function instantiate(string $class): ?object
    {
        if (class_exists($class)) {
            if (!isset($this->cachedDependencies[$class])) {
                $this->cachedDependencies[$class] = $this
                    ->getDependencyResolver()
                    ->resolveDependencies($class);
            }

            /** @psalm-suppress MixedMethodCall */
            return new $class(...$this->cachedDependencies[$class]);
        }

        return null;
    }

    /**
     * Get the number of cached dependency resolutions.
     */
    public function getCacheSize(): int
    {
        return count($this->cachedDependencies);
    }

    /**
     * Get all cached class names.
     *
     * @return list<string>
     */
    public function getCachedClasses(): array
    {
        return array_keys($this->cachedDependencies);
    }

    private function getDependencyResolver(): DependencyResolver
    {
        if ($this->dependencyResolver === null) {
            $this->dependencyResolver = new DependencyResolver(
                $this->bindings,
                $this->contextualBindings,
            );
        }

        return $this->dependencyResolver;
    }
}
