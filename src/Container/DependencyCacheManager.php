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
 * Manages dependency resolution caching for performance optimization.
 */
final class DependencyCacheManager
{
    /** @var array<class-string|string, list<mixed>> */
    private array $cachedDependencies = [];

    /** @var array<class-string, object> */
    private array $singletonInstances = [];

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
        if (!class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        // Check for #[Singleton] attribute
        $singletonAttributes = $reflection->getAttributes(Singleton::class);
        if (count($singletonAttributes) > 0) {
            if (isset($this->singletonInstances[$class])) {
                return $this->singletonInstances[$class];
            }

            $instance = $this->createInstance($class);
            $this->singletonInstances[$class] = $instance;
            return $instance;
        }

        // Check for #[Factory] attribute - always create new instance
        $factoryAttributes = $reflection->getAttributes(Factory::class);
        if (count($factoryAttributes) > 0) {
            // Don't cache dependencies for factory classes to ensure fresh instances
            $dependencies = $this
                ->getDependencyResolver()
                ->resolveDependencies($class);

            /** @psalm-suppress MixedMethodCall */
            return new $class(...$dependencies);
        }

        // Default behavior - create new instance
        return $this->createInstance($class);
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

    /**
     * Create a new instance of a class using cached dependencies.
     *
     * @param class-string $class
     */
    private function createInstance(string $class): object
    {
        if (!isset($this->cachedDependencies[$class])) {
            $this->cachedDependencies[$class] = $this
                ->getDependencyResolver()
                ->resolveDependencies($class);
        }

        /** @psalm-suppress MixedMethodCall */
        return new $class(...$this->cachedDependencies[$class]);
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
