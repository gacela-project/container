<?php

declare(strict_types=1);

namespace Gacela\Container;

use Closure;
use Gacela\Container\Exception\ContainerException;

use ReflectionClass;
use ReflectionNamedType;

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
        if (!class_exists($className)) {
            return [];
        }

        $dependencies = [];
        $this->collectDependencies($className, $dependencies);

        /** @var list<string> */
        return array_keys($dependencies);
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
     * @param class-string $className
     * @param array<string, true> $dependencies
     */
    private function collectDependencies(string $className, array &$dependencies): void
    {
        $reflection = new ReflectionClass($className);

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return;
        }

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            /** @var class-string $paramTypeName */
            $paramTypeName = $type->getName();

            // Resolve binding if it's an interface
            $paramTypeName = $this->bindingResolver->resolveType($paramTypeName);

            if (isset($dependencies[$paramTypeName])) {
                continue; // Already processed
            }

            $dependencies[$paramTypeName] = true;

            if (class_exists($paramTypeName)) {
                $this->collectDependencies($paramTypeName, $dependencies);
            }
        }
    }
}
