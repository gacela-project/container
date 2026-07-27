<?php

declare(strict_types=1);

namespace Gacela\Container;

use ArrayAccess;
use Closure;
use Gacela\Container\Exception\ContainerException;
use Gacela\Container\Exception\DependencyNotFoundException;
use Override;
use ReflectionClass;

use function class_exists;
use function count;
use function is_callable;
use function is_object;

/**
 * @psalm-import-type Binding from ContainerInterface
 * @psalm-import-type BindingsMap from ContainerInterface
 * @psalm-import-type ContextualBindingsMap from ContainerInterface
 * @psalm-import-type CompiledPlans from DependencyResolver
 *
 * @implements ArrayAccess<string, mixed>
 *
 * @api
 */
final class Container implements ContainerInterface, ArrayAccess
{
    private AliasRegistry $aliasRegistry;

    private TagRegistry $tagRegistry;

    private FactoryManager $factoryManager;

    private InstanceRegistry $instanceRegistry;

    private DependencyCacheManager $cacheManager;

    private BindingResolver $bindingResolver;

    private DependencyTreeAnalyzer $dependencyTreeAnalyzer;

    /** @var self|null the container this one was created as a scope of */
    private ?self $parent = null;

    /** @var BindingsMap */
    private array $bindings;

    /** @var ContextualBindingsMap */
    private array $contextualBindings = [];

    /** @var array<string, list<Closure>> */
    private array $afterResolvingCallbacks = [];

    /** @var array<string, bool> memoizes has()'s instantiability probe */
    private array $instantiableCache = [];

    /** @var array<class-string, callable(): object> */
    private array $compiledFactories = [];

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
        return CompiledCacheWriter::read($file);
    }

    /**
     * A child container that resolves everything this one resolves, plus
     * whatever is registered on it directly.
     *
     * Nothing is copied. The scope starts empty and falls through to this
     * container on a miss, so creating one costs the same whether the parent
     * holds three registrations or three thousand — cheap enough to do per
     * request, or per module. Anything registered on the scope shadows the
     * parent for that scope alone and never mutates it.
     *
     * Lifetime follows first resolution. A service this container has already
     * resolved is handed to every scope, so they share one instance; one a
     * scope resolves first belongs to that scope and goes away with it. That is
     * what makes a scope usable as a request lifetime in a long-running
     * runtime: drop the reference and everything it resolved is released, while
     * everything resolved at boot stays put.
     *
     * Two things deliberately do not fall through. remove() only forgets what
     * the scope itself stored, and extend() refuses to reach into an ancestor
     * — see the exception it throws for the way to decorate a scope-locally.
     *
     * Deliberately not on ContainerInterface — 1.x promises no method will be
     * added there. It moves onto the interface in 2.0.
     */
    public function createScope(): self
    {
        $scope = new self();
        $scope->parent = $this;

        // The one thing a scope takes a copy of, rather than looking up as it
        // goes. Contextual bindings are matched against the resolver's build
        // stack, so a chain walk would land on the hot path of every nested
        // parameter to serve a map that is configuration, fixed before the
        // scopes that read it exist. Copy-on-write makes the copy free until
        // the scope defines one of its own. A when() call on this container
        // after the scope exists is therefore not visible to it.
        $scope->contextualBindings = $this->contextualBindings;

        // Same reasoning, and the same copy-on-write: a generated factory is a
        // plain `new` expression for a class nobody has bound, so it stays
        // correct in a scope, and looking the map up through the chain would
        // cost every miss on the resolution path.
        $scope->compiledFactories = $this->compiledFactories;

        $scope->aliasRegistry->inheritFrom($this->aliasRegistry);
        $scope->bindingResolver->inheritFrom($this->bindingResolver);
        $scope->cacheManager->inheritFrom($this->cacheManager, $this);

        return $scope;
    }

    /**
     * Whether this container, or one of its ancestors, already owns something
     * for $id: a binding, a stored instance, or a singleton it has resolved.
     *
     * Narrower than has(), which is also true of anything merely autowirable,
     * and wider than bound(), which does not see a singleton that no binding
     * introduced. A scope asks this to decide whether to delegate upwards or
     * build its own.
     *
     * Deliberately not on ContainerInterface — 1.x promises no method will be
     * added there.
     */
    public function provides(string $id): bool
    {
        $id = $this->aliasRegistry->resolve($id);

        return $this->ownsLocally($id) || ($this->parent?->provides($id) ?? false);
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
    #[Override]
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
    #[Override]
    public function writeCompiledCache(array $classNames, string $file): void
    {
        CompiledCacheWriter::write($this->compile($classNames), $file);
    }

    /**
     * Write plain `new` expressions for the classes whose construction is
     * fully knowable ahead of time, as a `class-string => Closure(): object`
     * map.
     *
     * Deliberately conservative: anything depending on a binding, a scalar, an
     * attribute or a contextual binding is left out and keeps resolving
     * normally. The file is an optimisation, never a second resolver.
     *
     * Feed it back with `useCompiledFactories()`, and regenerate it whenever a
     * compiled constructor changes.
     *
     * @param list<class-string> $classNames
     *
     * @return list<class-string> the classes that were compiled
     */
    public function writeCompiledFactories(array $classNames, string $file): array
    {
        $compiler = new ContainerCompiler($this->compile($classNames), $this->getBindings());

        CompiledCacheWriter::put($file, $compiler->render());

        return $compiler->compilable();
    }

    /**
     * Use previously generated factories as a fast path for `get()`/`make()`.
     *
     * @param array<class-string, callable(): object> $factories
     */
    public function useCompiledFactories(array $factories): void
    {
        $this->compiledFactories = $factories;
    }

    /**
     * Register a binding from an abstract type to a concrete implementation.
     *
     * @param Binding $concrete
     */
    #[Override]
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
    #[Override]
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
    #[Override]
    public function bound(string $id): bool
    {
        $id = $this->aliasRegistry->resolve($id);

        return isset($this->bindings[$id])
            || $this->instanceRegistry->has($id)
            || ($this->parent?->bound($id) ?? false);
    }

    /**
     * Register a binding only if the abstract is not already bound.
     *
     * @param Binding $concrete
     */
    #[Override]
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
    #[Override]
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

    /**
     * PSR-11: true when get($id) will resolve without throwing.
     *
     * Because this container autowires, that covers more than what was
     * explicitly registered. Use bound() for the narrower question of whether
     * an id has a binding or a stored instance.
     */
    #[Override]
    public function has(string $id): bool
    {
        $id = $this->aliasRegistry->resolve($id);

        if ($this->instanceRegistry->has($id) || isset($this->bindings[$id])) {
            return true;
        }

        if ($this->parent?->bound($id) === true) {
            return true;
        }

        return $this->isInstantiable($id);
    }

    #[Override]
    public function offsetExists(mixed $offset): bool
    {
        return $this->has($offset);
    }

    #[Override]
    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }

    #[Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set((string) $offset, $value);
    }

    #[Override]
    public function offsetUnset(mixed $offset): void
    {
        $this->remove($offset);
    }

    #[Override]
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
    #[Override]
    public function get(string $id): mixed
    {
        $id = $this->aliasRegistry->resolve($id);

        // Deliberately not has(): that answers "is this resolvable at all?",
        // which is true for autowirable classes that have no stored instance.
        if ($this->instanceRegistry->has($id)) {
            /** @var mixed $instance */
            $instance = $this->instanceRegistry->get($id, $this->factoryManager, $this);
        } elseif ($this->parent !== null && !$this->ownsLocally($id) && $this->parent->provides($id)) {
            // The ancestor owns it, so it resolves it: its own factory and
            // protected closures apply, and every scope gets the same instance.
            /** @var mixed $instance */
            $instance = $this->parent->get($id);
        } else {
            $instance = $this->createInstance($id);
        }

        $this->fireAfterResolving($id, $instance);

        return $instance;
    }

    /**
     * Register a callback to run after the given id is resolved, receiving the
     * resolved instance and the container. Callbacks run in registration order.
     *
     * Callbacks fire for the resolutions their own container performs. A scope
     * resolving an id its parent registered is a resolution the parent
     * performs, so callbacks registered up there still fire; one the scope
     * autowires by itself is not, so they do not.
     */
    #[Override]
    public function afterResolving(string $id, Closure $callback): void
    {
        $id = $this->aliasRegistry->resolve($id);
        $this->afterResolvingCallbacks[$id][] = $callback;
    }

    /**
     * Like get(), but throws when the id resolves to null instead of returning it.
     */
    #[Override]
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
    #[Override]
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
    #[Override]
    public function resolve(callable $callable, array $parameters = []): mixed
    {
        $callableKey = CallableKey::for($callable);
        $closure = Closure::fromCallable($callable);

        $dependencies = $this->cacheManager->resolveCallableDependencies($callableKey, $closure, $parameters);

        /** @psalm-suppress MixedMethodCall */
        return $closure(...$dependencies);
    }

    #[Override]
    public function factory(Closure $instance): Closure
    {
        $this->factoryManager->markAsFactory($instance);

        return $instance;
    }

    /**
     * Forget an instance stored on this container.
     *
     * A scope only forgets what it stored itself; removing an id it inherits
     * would mutate the ancestor holding it. After removing a shadowing entry,
     * the id resolves through the parent again.
     */
    #[Override]
    public function remove(string $id): void
    {
        $id = $this->aliasRegistry->resolve($id);
        $this->instanceRegistry->remove($id);
    }

    #[Override]
    public function alias(string $alias, string $id): void
    {
        $this->aliasRegistry->add($alias, $id);
    }

    /**
     * Group one or more service ids under a tag. Calls accumulate and dedupe.
     *
     * @param string|list<string> $ids
     */
    #[Override]
    public function tag(string|array $ids, string $tag): void
    {
        $this->tagRegistry->tag($ids, $tag);
    }

    /**
     * Lazily resolve every service registered under a tag, in insertion order.
     *
     * @return iterable<mixed>
     */
    #[Override]
    public function tagged(string $tag): iterable
    {
        foreach ($this->taggedIds($tag) as $id) {
            yield $this->get($id);
        }
    }

    /**
     * @param class-string $className
     *
     * @return list<string>
     */
    #[Override]
    public function getDependencyTree(string $className): array
    {
        return $this->dependencyTreeAnalyzer->analyze($className);
    }

    /**
     * @psalm-suppress MixedAssignment
     */
    #[Override]
    public function extend(string $id, Closure $instance): Closure
    {
        $id = $this->aliasRegistry->resolve($id);

        // Deliberately not has(): that asks whether get() would resolve the id,
        // which is true for any instantiable class. Here the question is
        // narrower — is there something stored to extend right now?
        if (!$this->instanceRegistry->has($id)) {
            // Scheduling would be a silent no-op: anything an ancestor owns is
            // resolved by that ancestor, so the pending extension here would
            // never fire. A binding counts, not just a stored instance.
            if ($this->parent?->provides($id) === true) {
                throw ContainerException::inheritedInstanceExtend($id);
            }

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

    #[Override]
    public function protect(Closure $instance): Closure
    {
        $this->factoryManager->markAsProtected($instance);

        return $instance;
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function getRegisteredServices(): array
    {
        $own = $this->instanceRegistry->getAll();

        if ($this->parent === null) {
            return $own;
        }

        // A scope can resolve what its ancestors stored, so it reports those
        // too; an id it shadows is listed once, under the scope.
        return array_values(array_unique([...$this->parent->getRegisteredServices(), ...$own]));
    }

    #[Override]
    public function isFactory(string $id): bool
    {
        $id = $this->aliasRegistry->resolve($id);

        // Same distinction as extend(): only a stored instance can be a factory.
        if (!$this->instanceRegistry->has($id)) {
            return $this->parent?->isFactory($id) ?? false;
        }

        return $this->factoryManager->isFactory($this->instanceRegistry->getRaw($id));
    }

    #[Override]
    public function isFrozen(string $id): bool
    {
        $id = $this->aliasRegistry->resolve($id);

        // Same distinction as isFactory(): a scope shadowing an id answers for
        // its own copy, which is unfrozen however long the ancestor's has been
        // read.
        if ($this->instanceRegistry->has($id)) {
            return $this->instanceRegistry->isFrozen($id);
        }

        return $this->parent?->isFrozen($id) ?? false;
    }

    /**
     * @return BindingsMap
     */
    #[Override]
    public function getBindings(): array
    {
        return $this->bindingResolver->getBindings();
    }

    /**
     * @param list<class-string> $classNames
     */
    #[Override]
    public function warmUp(array $classNames): void
    {
        $this->cacheManager->warmUp($classNames);
    }

    /**
     * Define a contextual binding.
     *
     * @param class-string|list<class-string> $concrete
     */
    #[Override]
    public function when(string|array $concrete): ContextualBindingBuilder
    {
        $builder = new ContextualBindingBuilder($this->contextualBindings);
        $builder->when($concrete);

        return $builder;
    }

    /**
     * A snapshot of what this container is holding.
     *
     * Prefer this over getStats(): the returned object's shape is covered by
     * backward compatibility, where the array's explicitly is not, and memory
     * comes back as an int rather than a string needing to be parsed.
     *
     * Deliberately not on ContainerInterface — 1.x promises no method will be
     * added there. It moves onto the interface in 2.0, replacing getStats().
     */
    public function stats(): ContainerStats
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

        return new ContainerStats(
            registeredServices: count($services),
            frozenServices: $frozenCount,
            factoryServices: $factoryCount,
            bindings: count($this->getBindings()),
            cachedDependencies: $this->cacheManager->getCacheSize(),
            memoryUsageBytes: memory_get_usage(true),
        );
    }

    /**
     * Superseded by stats(), which is typed. Kept for the whole of 1.x.
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
    #[Override]
    public function getStats(): array
    {
        // Built from stats() rather than alongside it: two APIs over one set of
        // numbers can only stay in agreement if there is one place computing
        // them.
        $stats = $this->stats();

        return [
            'registered_services' => $stats->registeredServices,
            'frozen_services' => $stats->frozenServices,
            'factory_services' => $stats->factoryServices,
            'bindings' => $stats->bindings,
            'cached_dependencies' => $stats->cachedDependencies,
            'memory_usage' => $stats->memoryUsageFormatted(),
        ];
    }

    /**
     * Inherited tags come first, so a scope adding to a tag appends to what the
     * parent already grouped under it rather than replacing it.
     *
     * @return list<string>
     */
    private function taggedIds(string $tag): array
    {
        $own = $this->tagRegistry->idsFor($tag);

        if ($this->parent === null) {
            return $own;
        }

        return array_values(array_unique([...$this->parent->taggedIds($tag), ...$own]));
    }

    /**
     * Whether this container owns $id itself, ignoring its ancestors.
     *
     * get() has to ask exactly what provides() asks, only without the chain
     * walk. Anything narrower and a scope hands back an ancestor's instance for
     * something it had already resolved for itself.
     */
    private function ownsLocally(string $id): bool
    {
        return isset($this->bindings[$id])
            || $this->instanceRegistry->has($id)
            || $this->cacheManager->ownsSingleton($id);
    }

    private function isInstantiable(string $id): bool
    {
        if (isset($this->instantiableCache[$id])) {
            return $this->instantiableCache[$id];
        }

        if (!class_exists($id)) {
            return $this->instantiableCache[$id] = false;
        }

        return $this->instantiableCache[$id] = (new ReflectionClass($id))->isInstantiable();
    }

    private function fireAfterResolving(string $id, mixed $instance): void
    {
        if ($this->afterResolvingCallbacks === []) {
            return;
        }

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
        if ($this->compiledFactories !== []) {
            $factory = $this->compiledFactories[$class] ?? null;

            if ($factory !== null) {
                return $factory();
            }
        }

        return $this->bindingResolver->resolve($class, $this->cacheManager);
    }

    /**
     * @psalm-suppress MixedReturnTypeCoercion
     */
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
}
