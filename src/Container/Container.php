<?php

declare(strict_types=1);

namespace Gacela\Container;

use Closure;
use Gacela\Container\Exception\ContainerException;

use function count;
use function get_class;
use function is_array;
use function is_object;
use function is_string;

class Container implements ContainerInterface
{
    private AliasRegistry $aliasRegistry;

    private FactoryManager $factoryManager;

    private InstanceRegistry $instanceRegistry;

    private DependencyCacheManager $cacheManager;

    private BindingResolver $bindingResolver;

    private DependencyTreeAnalyzer $dependencyTreeAnalyzer;

    /**
     * @param  array<class-string, class-string|callable|object>  $bindings
     * @param  array<string, list<Closure>>  $instancesToExtend
     */
    public function __construct(
        array $bindings = [],
        array $instancesToExtend = [],
    ) {
        $this->aliasRegistry = new AliasRegistry();
        $this->factoryManager = new FactoryManager($instancesToExtend);
        $this->instanceRegistry = new InstanceRegistry();
        $this->bindingResolver = new BindingResolver($bindings);
        $this->cacheManager = new DependencyCacheManager($bindings);
        $this->dependencyTreeAnalyzer = new DependencyTreeAnalyzer($this->bindingResolver);
    }

    /**
     * @param  class-string  $className
     */
    public static function create(string $className): mixed
    {
        return (new self())->get($className);
    }

    public function has(string $id): bool
    {
        $id = $this->aliasRegistry->resolve($id);
        return $this->instanceRegistry->has($id);
    }

    public function set(string $id, mixed $instance): void
    {
        $this->instanceRegistry->set($id, $instance);

        if ($this->factoryManager->isCurrentlyExtending($id)) {
            return;
        }

        $this->extendService($id);
    }

    /**
     * @param  class-string|string  $id
     */
    public function get(string $id): mixed
    {
        $id = $this->aliasRegistry->resolve($id);

        if ($this->has($id)) {
            return $this->instanceRegistry->get($id, $this->factoryManager, $this);
        }

        return $this->createInstance($id);
    }

    public function resolve(callable $callable): mixed
    {
        $callableKey = $this->callableKey($callable);
        $closure = Closure::fromCallable($callable);

        $dependencies = $this->cacheManager->resolveCallableDependencies($callableKey, $closure);

        /** @psalm-suppress MixedMethodCall */
        return $closure(...$dependencies);
    }

    public function factory(Closure $instance): Closure
    {
        $this->factoryManager->markAsFactory($instance);

        return $instance;
    }

    public function remove(string $id): void
    {
        $id = $this->aliasRegistry->resolve($id);
        $this->instanceRegistry->remove($id);
    }

    public function alias(string $alias, string $id): void
    {
        $this->aliasRegistry->add($alias, $id);
    }

    /**
     * @param class-string $className
     *
     * @return list<string>
     */
    public function getDependencyTree(string $className): array
    {
        return $this->dependencyTreeAnalyzer->analyze($className);
    }

    /**
     * @psalm-suppress MixedAssignment
     */
    public function extend(string $id, Closure $instance): Closure
    {
        $id = $this->aliasRegistry->resolve($id);

        if (!$this->has($id)) {
            $this->factoryManager->scheduleExtension($id, $instance);

            return $instance;
        }

        if ($this->instanceRegistry->isFrozen($id)) {
            throw ContainerException::frozenInstanceExtend($id);
        }

        $factory = $this->instanceRegistry->getRaw($id);

        if ($this->factoryManager->isProtected($factory)) {
            throw ContainerException::instanceProtected($id);
        }

        $extended = $this->factoryManager->generateExtendedInstance($instance, $factory, $this);
        $this->set($id, $extended);

        $this->factoryManager->transferFactoryStatus($factory, $extended);

        return $extended;
    }

    public function protect(Closure $instance): Closure
    {
        $this->factoryManager->markAsProtected($instance);

        return $instance;
    }

    /**
     * @return list<string>
     */
    public function getRegisteredServices(): array
    {
        return $this->instanceRegistry->getAll();
    }

    public function isFactory(string $id): bool
    {
        $id = $this->aliasRegistry->resolve($id);

        if (!$this->has($id)) {
            return false;
        }

        return $this->factoryManager->isFactory($this->instanceRegistry->getRaw($id));
    }

    public function isFrozen(string $id): bool
    {
        $id = $this->aliasRegistry->resolve($id);
        return $this->instanceRegistry->isFrozen($id);
    }

    /**
     * @return array<class-string, class-string|callable|object>
     */
    public function getBindings(): array
    {
        return $this->bindingResolver->getBindings();
    }

    /**
     * @param list<class-string> $classNames
     */
    public function warmUp(array $classNames): void
    {
        $this->cacheManager->warmUp($classNames);
    }

    /**
     * Get container statistics for debugging and optimization.
     *
     * @return array{
     *     registered_services: int,
     *     frozen_services: int,
     *     factory_services: int,
     *     bindings: int,
     *     cached_dependencies: int,
     *     memory_usage: string
     * }
     */
    public function getStats(): array
    {
        $services = $this->getRegisteredServices();
        $frozenCount = 0;
        $factoryCount = 0;

        foreach ($services as $serviceId) {
            if ($this->isFrozen($serviceId)) {
                ++$frozenCount;
            }
            if ($this->isFactory($serviceId)) {
                ++$factoryCount;
            }
        }

        return [
            'registered_services' => count($services),
            'frozen_services' => $frozenCount,
            'factory_services' => $factoryCount,
            'bindings' => count($this->getBindings()),
            'cached_dependencies' => $this->cacheManager->getCacheSize(),
            'memory_usage' => $this->formatBytes(memory_get_usage(true)),
        ];
    }

    private function createInstance(string $class): ?object
    {
        return $this->bindingResolver->resolve($class, $this->cacheManager);
    }

    /**
     * Generates a unique string key for a given callable.
     *
     * @psalm-suppress MixedReturnTypeCoercion
     */
    private function callableKey(callable $callable): string
    {
        if (is_array($callable)) {
            [$classOrObject, $method] = $callable;

            $className = is_object($classOrObject)
                ? get_class($classOrObject) . '#' . spl_object_id($classOrObject)
                : $classOrObject;

            return $className . '::' . $method;
        }

        if (is_string($callable)) {
            return $callable;
        }

        if ($callable instanceof Closure) {
            return spl_object_hash($callable);
        }

        // Invokable objects
        /** @psalm-suppress RedundantCondition */
        if (is_object($callable)) {
            return get_class($callable) . '#' . spl_object_id($callable);
        }

        // Fallback for edge cases
        /** @psalm-suppress MixedArgument */
        return 'callable:' . md5(serialize($callable));
    }

    private function extendService(string $id): void
    {
        if (!$this->factoryManager->hasPendingExtensions($id)) {
            return;
        }

        $this->factoryManager->setCurrentlyExtending($id);

        foreach ($this->factoryManager->getPendingExtensions($id) as $instance) {
            $this->extend($id, $instance);
        }

        $this->factoryManager->clearPendingExtensions($id);
        $this->factoryManager->setCurrentlyExtending(null);
    }

    /**
     * Format bytes into human-readable format.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        /** @var int $powInt */
        $powInt = (int) $pow;
        $bytes /= (1 << (10 * $powInt));

        return round($bytes, 2) . ' ' . $units[$powInt];
    }
}
