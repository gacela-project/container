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
 * Resolves dependencies (delegating reflection caching to the resolver),
 * keeps singleton instances, and caches attribute lookups.
 *
 * @psalm-import-type BindingsMap from ContainerInterface
 * @psalm-import-type ContextualBindingsMap from ContainerInterface
 */
final class DependencyCacheManager
{
    /**
     * Keys (class names / callable keys) resolved at least once.
     * Dependencies are intentionally rebuilt per resolution so that transient
     * services do not share their child instances; only reflection is cached
     * (in the resolver).
     *
     * @var array<string, true>
     */
    private array $resolvedKeys = [];

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
        $this->resolvedKeys[$callableKey] = true;

        return $this->getDependencyResolver()->resolveDependencies($callable);
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

            // Warm the resolver's reflection caches for this class.
            $this->getDependencyResolver()->resolveDependencies($className);
            $this->resolvedKeys[$className] = true;
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
        return count($this->resolvedKeys);
    }

    /**
     * @param class-string $class
     */
    private function createInstance(string $class): object
    {
        $this->resolvedKeys[$class] = true;

        $dependencies = $this->getDependencyResolver()->resolveDependencies($class);

        /** @psalm-suppress MixedMethodCall */
        return new $class(...$dependencies);
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
