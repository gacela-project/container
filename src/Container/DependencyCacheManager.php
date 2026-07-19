<?php

declare(strict_types=1);

namespace Gacela\Container;

use Closure;
use Gacela\Container\Attribute\Factory;
use Gacela\Container\Attribute\Singleton;
use ReflectionClass;

use function class_exists;
use function count;

/**
 * Caches dependency resolutions, singleton instances, and attribute lookups.
 *
 * @psalm-import-type BindingsMap from ContainerInterface
 * @psalm-import-type ContextualBindingsMap from ContainerInterface
 */
final class DependencyCacheManager
{
    /** @var array<string, list<mixed>> */
    private array $cachedDependencies = [];

    /** @var array<class-string, object> */
    private array $singletonInstances = [];

    /** @var array<string, bool> Cache for attribute existence checks */
    private array $attributeCache = [];

    /** @var array<class-string, true> Classes forced to behave as singletons at runtime */
    private array $forcedSingletons = [];

    private ?DependencyResolver $dependencyResolver = null;

    /**
     * @param BindingsMap $bindings
     * @param ContextualBindingsMap $contextualBindings
     */
    public function __construct(
        private array &$bindings = [],
        private array &$contextualBindings = [],
    ) {
    }

    /**
     * @param class-string $class
     */
    public function markAsSingleton(string $class): void
    {
        $this->forcedSingletons[$class] = true;
    }

    /**
     * @return list<mixed>
     */
    public function resolveCallableDependencies(string $callableKey, Closure $callable): array
    {
        return $this->resolveCachedDependencies($callableKey, $callable);
    }

    /**
     * @param list<class-string> $classNames
     */
    public function warmUp(array $classNames): void
    {
        foreach ($classNames as $className) {
            if (!class_exists($className)) {
                continue;
            }

            $this->resolveCachedDependencies($className, $className);
        }
    }

    /**
     * @param class-string $class
     */
    public function instantiate(string $class): object
    {
        if (isset($this->forcedSingletons[$class]) || $this->hasAttribute($class, Singleton::class)) {
            if (isset($this->singletonInstances[$class])) {
                return $this->singletonInstances[$class];
            }

            $instance = $this->createInstance($class);
            $this->singletonInstances[$class] = $instance;
            return $instance;
        }

        if ($this->hasAttribute($class, Factory::class)) {
            // Don't cache dependencies for factory classes to ensure fresh instances
            $dependencies = $this
                ->getDependencyResolver()
                ->resolveDependencies($class);

            /** @psalm-suppress MixedMethodCall */
            return new $class(...$dependencies);
        }

        return $this->createInstance($class);
    }

    public function getCacheSize(): int
    {
        return count($this->cachedDependencies);
    }

    /**
     * @param class-string $class
     */
    private function createInstance(string $class): object
    {
        /** @psalm-suppress MixedMethodCall */
        return new $class(...$this->resolveCachedDependencies($class, $class));
    }

    /**
     * @param class-string|Closure $toResolve
     *
     * @return list<mixed>
     */
    private function resolveCachedDependencies(string $cacheKey, string|Closure $toResolve): array
    {
        if (!isset($this->cachedDependencies[$cacheKey])) {
            $this->cachedDependencies[$cacheKey] = $this
                ->getDependencyResolver()
                ->resolveDependencies($toResolve);
        }

        return $this->cachedDependencies[$cacheKey];
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

    /**
     * @param class-string $class
     * @param class-string $attributeClass
     */
    private function hasAttribute(string $class, string $attributeClass): bool
    {
        $cacheKey = $class . '::' . $attributeClass;

        if (!isset($this->attributeCache[$cacheKey])) {
            $reflection = new ReflectionClass($class);
            $this->attributeCache[$cacheKey] = count($reflection->getAttributes($attributeClass)) > 0;
        }

        return $this->attributeCache[$cacheKey];
    }
}
