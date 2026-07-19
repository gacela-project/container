<?php

declare(strict_types=1);

namespace Gacela\Container;

use Closure;
use Gacela\Container\Exception\ContainerException;
use Gacela\Container\Exception\DependencyNotFoundException;
use Throwable;

use function count;
use function file_put_contents;
use function get_class;
use function implode;
use function is_array;
use function is_callable;
use function is_object;
use function is_string;
use function var_export;

/**
 * @psalm-import-type Binding from ContainerInterface
 * @psalm-import-type BindingsMap from ContainerInterface
 * @psalm-import-type ContextualBindingsMap from ContainerInterface
 * @psalm-import-type CompiledPlans from DependencyResolver
 */
class Container implements ContainerInterface
{
    private AliasRegistry $aliasRegistry;

    private TagRegistry $tagRegistry;

    private FactoryManager $factoryManager;

    private InstanceRegistry $instanceRegistry;

    private DependencyCacheManager $cacheManager;

    private BindingResolver $bindingResolver;

    private DependencyTreeAnalyzer $dependencyTreeAnalyzer;

    /** @var BindingsMap */
    private array $bindings;

    /** @var ContextualBindingsMap */
    private array $contextualBindings = [];

    /** @var array<string, list<Closure>> */
    private array $afterResolvingCallbacks = [];

    /**
     * @param  BindingsMap  $bindings
     * @param  array<string, list<Closure>>  $instancesToExtend
     * @param  CompiledPlans  $compiledPlans  precompiled constructor plans (see writeCompiledCache())
     */
    public function __construct(
        array $bindings = [],
        array $instancesToExtend = [],
        array $compiledPlans = [],
    ) {
        $this->bindings = $bindings;
        $this->aliasRegistry = new AliasRegistry();
        $this->tagRegistry = new TagRegistry();
        $this->factoryManager = new FactoryManager($instancesToExtend);
        $this->instanceRegistry = new InstanceRegistry();
        $this->bindingResolver = new BindingResolver($this->bindings, $this);
        $this->cacheManager = new DependencyCacheManager($this->bindings, $this->contextualBindings, $compiledPlans, $this);
        $this->dependencyTreeAnalyzer = new DependencyTreeAnalyzer($this->bindingResolver);
    }

    /**
     * Load previously compiled constructor plans from a cache file.
     *
     * @return CompiledPlans
     */
    public static function loadCompiledCache(string $file): array
    {
        /**
         * @psalm-suppress UnresolvableInclude
         *
         * @var CompiledPlans $plans
         */
        $plans = require $file;

        return $plans;
    }

    /**
     * Warm up the given classes and return their compiled constructor plans.
     * Skips reflection at runtime when the plans are fed back via the
     * constructor's $compiledPlans argument.
     *
     * @param list<class-string> $classNames
     *
     * @return CompiledPlans
     */
    public function compile(array $classNames): array
    {
        $this->warmUp($classNames);

        return $this->cacheManager->exportCompiledPlans();
    }

    /**
     * Compile the given classes and write their constructor plans to an
     * opcache-friendly PHP file. Classes whose default values cannot be
     * exported are skipped and fall back to reflection at runtime.
     *
     * @param list<class-string> $classNames
     */
    public function writeCompiledCache(array $classNames, string $file): void
    {
        $entries = [];
        foreach ($this->compile($classNames) as $class => $plan) {
            try {
                $exportedPlan = var_export($plan, true);
            } catch (Throwable) {
                // A default value is not statically exportable; skip this class.
                continue;
            }
            $entries[] = var_export($class, true) . ' => ' . $exportedPlan . ',';
        }

        $code = "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n" . implode("\n", $entries) . "\n];\n";
        file_put_contents($file, $code);
    }

    /**
     * Register a binding from an abstract type to a concrete implementation.
     *
     * @param Binding $concrete
     */
    public function bind(string $abstract, string|callable|object $concrete): void
    {
        /**
         * @psalm-suppress PropertyTypeCoercion
         *
         * @phpstan-ignore assign.propertyType
         */
        $this->bindings[$abstract] = $concrete;
    }

    /**
     * Register a binding whose resolved instance is created once and reused.
     *
     * @param Binding|null $concrete when null, $abstract is the concrete class
     */
    public function singleton(string $abstract, string|callable|object|null $concrete = null): void
    {
        $concrete ??= $abstract;

        if (is_object($concrete) && !$concrete instanceof Closure) {
            // Already a single shared instance.
            $this->bind($abstract, $concrete);
            return;
        }

        if (is_callable($concrete)) {
            $this->bind($abstract, $this->memoizeCallable($concrete));
            return;
        }

        /** @var class-string $concrete */
        $this->bind($abstract, $concrete);
        $this->cacheManager->markAsSingleton($concrete);
    }

    /**
     * Whether a binding or instance is registered for the given id (alias-aware).
     */
    public function bound(string $id): bool
    {
        $id = $this->aliasRegistry->resolve($id);

        return isset($this->bindings[$id]) || $this->instanceRegistry->has($id);
    }

    /**
     * Register a binding only if the abstract is not already bound.
     *
     * @param Binding $concrete
     */
    public function bindIf(string $abstract, string|callable|object $concrete): void
    {
        if (!$this->bound($abstract)) {
            $this->bind($abstract, $concrete);
        }
    }

    /**
     * Register a singleton binding only if the abstract is not already bound.
     *
     * @param Binding|null $concrete when null, $abstract is the concrete class
     */
    public function singletonIf(string $abstract, string|callable|object|null $concrete = null): void
    {
        if (!$this->bound($abstract)) {
            $this->singleton($abstract, $concrete);
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $className
     *
     * @return T|null
     */
    public static function create(string $className): ?object
    {
        /** @var T|null $instance */
        $instance = (new self())->get($className);

        return $instance;
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
            /** @var mixed $instance */
            $instance = $this->instanceRegistry->get($id, $this->factoryManager, $this);
        } else {
            /** @var mixed $instance */
            $instance = $this->createInstance($id);
        }

        $this->fireAfterResolving($id, $instance);

        return $instance;
    }

    /**
     * Register a callback to run after the given id is resolved, receiving the
     * resolved instance and the container. Callbacks run in registration order.
     */
    public function afterResolving(string $id, Closure $callback): void
    {
        $id = $this->aliasRegistry->resolve($id);
        $this->afterResolvingCallbacks[$id][] = $callback;
    }

    /**
     * Like get(), but throws when the id resolves to null instead of returning it.
     */
    public function getOrFail(string $id): mixed
    {
        /** @psalm-suppress MixedAssignment */
        $instance = $this->get($id);
        if ($instance === null) {
            throw DependencyNotFoundException::unresolvableId($id);
        }

        return $instance;
    }

    /**
     * Resolve a class to a typed, non-null instance.
     *
     * When $parameters are given, they override constructor arguments by
     * parameter name (top level only) and the instance is always built fresh.
     *
     * @template T of object
     *
     * @param class-string<T> $className
     * @param array<string, mixed> $parameters
     *
     * @return T
     */
    public function make(string $className, array $parameters = []): object
    {
        if ($parameters === []) {
            /** @var T */
            return $this->getOrFail($className);
        }

        /** @var T */
        return $this->cacheManager->instantiateWith($className, $parameters);
    }

    /**
     * @param array<string, mixed> $parameters override arguments by parameter name
     */
    public function resolve(callable $callable, array $parameters = []): mixed
    {
        $callableKey = $this->callableKey($callable);
        $closure = Closure::fromCallable($callable);

        $dependencies = $this->cacheManager->resolveCallableDependencies($callableKey, $closure, $parameters);

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
     * Group one or more service ids under a tag. Calls accumulate and dedupe.
     *
     * @param string|list<string> $ids
     */
    public function tag(string|array $ids, string $tag): void
    {
        $this->tagRegistry->tag($ids, $tag);
    }

    /**
     * Lazily resolve every service registered under a tag, in insertion order.
     *
     * @return iterable<mixed>
     */
    public function tagged(string $tag): iterable
    {
        foreach ($this->tagRegistry->idsFor($tag) as $id) {
            yield $this->get($id);
        }
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

        $extended = $this->factoryManager->generateExtendedInstance($instance, $factory);
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
     * @return BindingsMap
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
     * Define a contextual binding.
     *
     * @param class-string|list<class-string> $concrete
     */
    public function when(string|array $concrete): ContextualBindingBuilder
    {
        $builder = new ContextualBindingBuilder($this->contextualBindings);
        $builder->when($concrete);

        return $builder;
    }

    /**
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

    private function fireAfterResolving(string $id, mixed $instance): void
    {
        foreach ($this->afterResolvingCallbacks[$id] ?? [] as $callback) {
            $callback($instance, $this);
        }
    }

    /**
     * @return Closure(): mixed
     */
    private function memoizeCallable(callable $factory): Closure
    {
        $container = $this;
        $resolved = null;
        $hasResolved = false;

        return static function () use ($factory, $container, &$resolved, &$hasResolved): mixed {
            if (!$hasResolved) {
                /** @var mixed $resolved */
                $resolved = $factory($container);
                $hasResolved = true;
            }

            return $resolved;
        };
    }

    private function createInstance(string $class): ?object
    {
        return $this->bindingResolver->resolve($class, $this->cacheManager);
    }

    /**
     * @psalm-suppress MixedReturnTypeCoercion
     */
    private function callableKey(callable $callable): string
    {
        if (is_array($callable)) {
            /** @var array{0: object|class-string, 1: string} $arrayCallable */
            $arrayCallable = $callable;
            $classOrObject = $arrayCallable[0];
            $method = $arrayCallable[1];

            $className = is_object($classOrObject)
                ? get_class($classOrObject) . '#' . spl_object_id($classOrObject)
                : $classOrObject;

            return $className . '::' . $method;
        }

        if (is_string($callable)) {
            return $callable;
        }

        // Only closures and invokable objects remain once array and string are ruled out
        /** @var callable&object $callable */
        return get_class($callable) . '#' . spl_object_id($callable);
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
