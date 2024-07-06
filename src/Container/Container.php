<?php

declare(strict_types=1);

namespace Gacela\Container;

use Closure;
use Gacela\Container\Exception\ContainerException;
use ReflectionFunction;
use SplObjectStorage;

use function count;
use function is_array;
use function is_callable;
use function is_object;

class Container implements ContainerInterface
{
    private ?DependencyResolver $dependencyResolver = null;

    /** @var array<class-string|string, list<mixed>> */
    private array $cachedDependencies = [];

    /** @var array<string,mixed> */
    private array $instances = [];

    private SplObjectStorage $factoryInstances;

    private SplObjectStorage $protectedInstances;

    /** @var array<string,bool> */
    private array $frozenInstances = [];

    private ?string $currentlyExtending = null;

    /**
     * @param array<class-string, class-string|callable|object> $bindings
     * @param array<string, list<Closure>> $instancesToExtend
     */
    public function __construct(
        private array $bindings = [],
        private array $instancesToExtend = [],
    ) {
        $this->factoryInstances = new SplObjectStorage();
        $this->protectedInstances = new SplObjectStorage();
    }

    /**
     * @param class-string $className
     */
    public static function create(string $className): mixed
    {
        return (new self())->get($className);
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]);
    }

    public function set(string $id, mixed $instance): void
    {
        if (!empty($this->frozenInstances[$id])) {
            throw ContainerException::frozenInstanceOverride($id);
        }

        $this->instances[$id] = $instance;

        if ($this->currentlyExtending === $id) {
            return;
        }

        $this->extendService($id);
    }

    /**
     * @param class-string|string $id
     */
    public function get(string $id): mixed
    {
        if ($this->has($id)) {
            return $this->getInstance($id);
        }

        return $this->createInstance($id);
    }

    public function resolve(callable $callable): mixed
    {
        $callable = Closure::fromCallable($callable);
        $reflectionFn = new ReflectionFunction($callable);
        $callableKey = md5(serialize($reflectionFn->__toString()));

        if (!isset($this->cachedDependencies[$callableKey])) {
            $this->cachedDependencies[$callableKey] = $this
                ->getDependencyResolver()
                ->resolveDependencies($callable);
        }

        /** @psalm-suppress MixedMethodCall */
        return $callable(...$this->cachedDependencies[$callableKey]);
    }

    public function factory(Closure $instance): Closure
    {
        $this->factoryInstances->attach($instance);

        return $instance;
    }

    public function remove(string $id): void
    {
        unset(
            $this->instances[$id],
            $this->frozenInstances[$id],
        );
    }

    /**
     * @psalm-suppress MixedAssignment
     */
    public function extend(string $id, Closure $instance): Closure
    {
        if (!$this->has($id)) {
            $this->extendLater($id, $instance);

            return $instance;
        }

        if (isset($this->frozenInstances[$id])) {
            throw ContainerException::frozenInstanceExtend($id);
        }

        if (is_object($this->instances[$id]) && isset($this->protectedInstances[$this->instances[$id]])) {
            throw ContainerException::instanceProtected($id);
        }

        $factory = $this->instances[$id];
        $extended = $this->generateExtendedInstance($instance, $factory);
        $this->set($id, $extended);

        return $extended;
    }

    public function protect(Closure $instance): Closure
    {
        $this->protectedInstances->attach($instance);

        return $instance;
    }

    private function getInstance(string $id): mixed
    {
        $this->frozenInstances[$id] = true;

        if (!is_object($this->instances[$id])
            || isset($this->protectedInstances[$this->instances[$id]])
            || !method_exists($this->instances[$id], '__invoke')
        ) {
            return $this->instances[$id];
        }

        if (isset($this->factoryInstances[$this->instances[$id]])) {
            return $this->instances[$id]($this);
        }

        $rawService = $this->instances[$id];

        /** @var mixed $resolvedService */
        $resolvedService = $rawService($this);

        $this->instances[$id] = $resolvedService;

        return $resolvedService;
    }

    private function createInstance(string $class): ?object
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
                return $this->instantiateClass($binding);
            }
        }

        if (class_exists($class)) {
            return $this->instantiateClass($class);
        }

        return null;
    }

    /**
     * @param class-string $class
     */
    private function instantiateClass(string $class): ?object
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

    private function extendLater(string $id, Closure $instance): void
    {
        $this->instancesToExtend[$id][] = $instance;
    }

    private function getDependencyResolver(): DependencyResolver
    {
        if ($this->dependencyResolver === null) {
            $this->dependencyResolver = new DependencyResolver(
                $this->bindings,
            );
        }

        return $this->dependencyResolver;
    }

    /**
     * @psalm-suppress MissingClosureReturnType,MixedAssignment
     */
    private function generateExtendedInstance(Closure $instance, mixed $factory): Closure
    {
        if (is_callable($factory)) {
            return static function (self $container) use ($instance, $factory) {
                $result = $factory($container);

                return $instance($result, $container) ?? $result;
            };
        }

        if (is_object($factory) || is_array($factory)) {
            return static fn (self $container) => $instance($factory, $container) ?? $factory;
        }

        throw ContainerException::instanceNotExtendable();
    }

    private function extendService(string $id): void
    {
        if (!isset($this->instancesToExtend[$id]) || count($this->instancesToExtend[$id]) === 0) {
            return;
        }
        $this->currentlyExtending = $id;

        foreach ($this->instancesToExtend[$id] as $instance) {
            $this->extend($id, $instance);
        }

        unset($this->instancesToExtend[$id]);
        $this->currentlyExtending = null;
    }
}
