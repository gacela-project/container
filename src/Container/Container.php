<?php

declare(strict_types=1);

namespace Gacela\Container;

use ArrayAccess;
use Closure;
use Gacela\Container\Exception\ContainerException;
use Gacela\Container\Exception\DependencyNotFoundException;
use Override;
use WeakReference;

use function count;
use function in_array;
use function is_callable;
use function is_int;
use function is_object;
use function is_string;
use function max;

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
    /**
     * Floor for the scope-sweep threshold, so a container with a handful of
     * scopes never sweeps at all.
     */
    private const int SCOPE_SWEEP_MIN = 16;

    private AliasRegistry $aliasRegistry;

    /**
     * Built on demand. Most containers never group a service under a tag, and
     * createScope() pays the constructor per scope — the operation whose whole
     * point is being cheap enough to run per request.
     */
    private ?TagRegistry $tagRegistry = null;

    private FactoryManager $factoryManager;

    private InstanceRegistry $instanceRegistry;

    private DependencyCacheManager $cacheManager;

    private BindingResolver $bindingResolver;

    /** Built on demand: getDependencyTree() is a debugging call. */
    private ?DependencyTreeAnalyzer $dependencyTreeAnalyzer = null;

    /** @var self|null the container this one was created as a scope of */
    private ?self $parent = null;

    /** @var BindingsMap */
    private array $bindings;

    /** @var ContextualBindingsMap */
    private array $contextualBindings = [];

    /** @var array<string, list<Closure>> */
    private array $afterResolvingCallbacks = [];

    /** @var array<class-string, callable(): object> */
    private array $compiledFactories = [];

    /**
     * The contextual bindings when() wrote on this container itself, as opposed
     * to the ones it inherited when it was created as a scope.
     *
     * Kept apart for one reason: a copy of an inherited binding is otherwise
     * indistinguishable from one the scope defined, and the two must behave
     * differently when the parent later changes that binding — the inherited
     * copy follows, the scope's own does not.
     *
     * @var ContextualBindingsMap
     */
    private array $ownContextualBindings = [];

    /**
     * Scopes created from this container, weakly, so a scope that has been
     * dropped is collected exactly as before rather than kept alive by its
     * parent's bookkeeping.
     *
     * @var list<WeakReference<self>>
     */
    private array $scopes = [];

    /** Size at which the scope list is swept for dead references. */
    private int $sweepScopesAt = self::SCOPE_SWEEP_MIN;

    /**
     * @param  BindingsMap  $bindings
     * @param  array<string, list<Closure>>  $instancesToExtend
     * @param  CompiledPlans  $compiledPlans  precompiled constructor plans (see writeCompiledCache())
     * @param  PlanCache|null  $planCache  one plan cache shared with containers this one is not related to;
     *   what it shares is reflection output, never configuration (see PlanCache)
     */
    public function __construct(
        array $bindings = [],
        array $instancesToExtend = [],
        array $compiledPlans = [],
        ?PlanCache $planCache = null,
    ) {
        $this->bindings = $bindings;
        $this->aliasRegistry = new AliasRegistry();
        $this->factoryManager = new FactoryManager($instancesToExtend);
        $this->instanceRegistry = new InstanceRegistry();
        $this->bindingResolver = new BindingResolver($this->bindings, $this);
        $this->cacheManager = new DependencyCacheManager(
            $this->bindings,
            $this->contextualBindings,
            $compiledPlans,
            $this,
            $planCache,
        );
    }

    /**
     * Load previously compiled constructor plans from a cache file.
     *
     * Every entry carries what it was compiled from, and one whose class file
     * has changed since is dropped here rather than served as if current: a
     * stale plan would build with the old constructor signature. A dropped
     * entry behaves exactly like one that was never written, so the class
     * falls back to reflection and correctness never depends on the cache
     * being up to date.
     *
     * @param string|null $buildStamp skips the per-entry check when it matches
     *   the value the file was written with, and discards the whole file when
     *   it does not — the cheaper trade for a large map, where one stat per
     *   class costs more than the reflection it saves
     *
     * @return CompiledPlans
     */
    public static function loadCompiledCache(string $file, ?string $buildStamp = null): array
    {
        return CompiledCacheWriter::read($file, $buildStamp);
    }

    /**
     * Load previously generated factories from a cache file, under the same
     * staleness rules as loadCompiledCache(). Feed the result to
     * useCompiledFactories().
     *
     * @return array<class-string, callable(): object>
     */
    public static function loadCompiledFactories(string $file, ?string $buildStamp = null): array
    {
        return CompiledCacheWriter::readFactories($file, $buildStamp);
    }

    /**
     * Clear the caches that outlive every container.
     *
     * Five of the container's caches are `static`, shared by every instance
     * and untouched by dropping one: reflection output keyed by class name. A
     * class definition cannot change within a process, so this is normally
     * free — and it is exactly wrong for a process where the set of loadable
     * classes *does* change: code generation, a cache-warm command, a worker
     * that re-bootstraps between jobs, a test suite that declares classes as
     * it goes.
     *
     * | Cache | Lifetime |
     * |---|---|
     * | property plans (`#[Inject]` scan output) | class shape — cleared |
     * | `#[Lazy]` on a class | class shape — cleared |
     * | `#[Singleton]`/`#[Factory]` on a class | class shape — cleared |
     * | proven-instantiable classes | class shape — cleared |
     * | has-`#[Inject]`-properties | class shape — cleared |
     * | native lazy objects available | the PHP binary — recomputed, never differs |
     *
     * Only positives are ever stored, so a class that was not loadable when it
     * was first asked about is not remembered as missing. Nothing here is a
     * correctness crutch: calling this only ever costs the reflection it
     * throws away.
     *
     * Deliberately not on ContainerInterface — 1.x promises no method will be
     * added there — and static, because there is no instance that owns this
     * state to ask.
     */
    public static function resetStaticCaches(): void
    {
        DependencyCacheManager::resetCache();
        DependencyResolver::resetCache();
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
        // parameter to serve a map that is configuration. Copy-on-write makes
        // the copy free until the scope defines one of its own, and a later
        // when() on this container is pushed down to the scopes that took a
        // copy — so the snapshot is an optimisation, not an ordering rule.
        $scope->contextualBindings = $this->contextualBindings;

        // Same reasoning, and the same copy-on-write: a generated factory is a
        // plain `new` expression for a class nobody has bound, so it stays
        // correct in a scope, and looking the map up through the chain would
        // cost every miss on the resolution path.
        $scope->compiledFactories = $this->compiledFactories;

        $scope->aliasRegistry->inheritFrom($this->aliasRegistry);
        $scope->bindingResolver->inheritFrom($this->bindingResolver);
        $scope->cacheManager->inheritFrom($this->cacheManager, $this);

        $this->rememberScope($scope);

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
     * Each entry is stamped with the declaring file it was compiled from, so
     * loadCompiledCache() can tell a current plan from one whose constructor
     * has since changed.
     *
     * @param list<class-string> $classNames
     * @param string|null $buildStamp a deploy id or commit sha identifying this
     *   build; pass the same value to loadCompiledCache() to validate the file
     *   in one comparison instead of one stat per class. Widening the
     *   implementation is safe where widening ContainerInterface would not be
     */
    #[Override]
    public function writeCompiledCache(array $classNames, string $file, ?string $buildStamp = null): void
    {
        CompiledCacheWriter::write($this->compile($classNames), $file, $buildStamp);
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
     * Read it back with `loadCompiledFactories()` and install it with
     * `useCompiledFactories()`. Entries are stamped like compiled plans are,
     * so a generated expression for a constructor that has since changed is
     * dropped on load rather than used.
     *
     * @param list<class-string> $classNames
     * @param string|null $buildStamp see writeCompiledCache()
     *
     * @return list<class-string> the classes that were compiled
     */
    public function writeCompiledFactories(array $classNames, string $file, ?string $buildStamp = null): array
    {
        $compiler = $this->compilerFor($classNames);

        CompiledCacheWriter::put($file, $compiler->render($buildStamp));

        return $compiler->compilable();
    }

    /**
     * What `writeCompiledFactories()` would make of these classes, and why it
     * refuses the ones it refuses.
     *
     * The generator is deliberately conservative and silent about it, which is
     * fine at runtime and unhelpful in a build. This turns that silence into an
     * answer a build script can assert on:
     *
     * ```php
     * $report = $container->compileReport([Foo::class, Bar::class]);
     *
     * foreach ($report->explanations() as $class => $why) {
     *     echo "skipped {$class}: {$why}\n";
     * }
     * ```
     *
     * Nothing is written and no state changes beyond the warm-up `compile()`
     * already performs. Its `compiled()` set is exactly what
     * `writeCompiledFactories()` returns for the same input.
     *
     * Deliberately not on ContainerInterface — 1.x promises no method will be
     * added there. It moves onto the interface in 2.0.
     *
     * @param list<class-string> $classNames
     */
    public function compileReport(array $classNames): CompilationReport
    {
        return $this->compilerFor($classNames)->report();
    }

    /**
     * Use previously generated factories as a fast path for `get()`/`make()`.
     *
     * @param array<class-string, callable(): object> $factories
     */
    public function useCompiledFactories(array $factories): void
    {
        $this->compiledFactories = $factories;

        $this->pushCompiledFactories($factories);
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
     * Register services from a definitions array, so wiring can be shipped and
     * overridden as data rather than code.
     *
     * ```php
     * $container->load([
     *     LoggerInterface::class => FileLogger::class,             // interface => concrete
     *     Database::class => ['singleton' => DatabasePool::class], // explicit lifetime
     *     'db.dsn' => ['value' => 'pgsql://localhost/app'],        // a config value
     *     'logger' => ['alias' => LoggerInterface::class],
     *     Metrics::class => ['singleton' => Metrics::class, 'tags' => ['reporters']],
     * ]);
     * ```
     *
     * Every entry ends up calling the registration method it stands for, so
     * laziness, freezing and scope behaviour are exactly what the imperative
     * equivalent would give you. Later calls override earlier keys, which makes
     * per-environment layering a matter of loading base then overrides — except
     * for 'tags', which accumulate the way tag() does.
     *
     * Deliberately not on ContainerInterface — 1.x promises no method will be
     * added there. It moves onto the interface in 2.0.
     *
     * @param array<array-key, mixed> $definitions
     *
     * @throws ContainerException when an entry names an unknown key, or a key's
     *                            value is not of the type it accepts
     */
    public function load(array $definitions): void
    {
        (new DefinitionLoader($this))->load($definitions);
    }

    /**
     * Load definitions from a '.php' file returning an array, or a '.json' file.
     *
     * YAML stays a userland concern — there is no parser here, and adding one
     * would mean a second runtime dependency:
     *
     * ```php
     * $container->load(Yaml::parseFile('services.yaml'));
     * ```
     *
     * Deliberately not on ContainerInterface — see load().
     *
     * @throws ContainerException when the file is missing, unreadable, of an
     *                            unsupported type, or does not hold an array
     */
    public function loadFile(string $file): void
    {
        (new DefinitionLoader($this))->loadFile($file);
    }

    /**
     * Defer the construction of a service until it is first used, without
     * putting #[Lazy] on the class.
     *
     * ```php
     * $container->lazy(ReportGenerator::class);                     // a class you cannot annotate
     * $container->lazy(ReportsInterface::class, PdfReports::class); // an abstract, lazily bound
     * $container->lazy(PdfReports::class, fn (Container $c) => new PdfReports($c->get(Db::class)));
     * ```
     *
     * The first two forms return a lazy ghost, exactly like the attribute. The
     * closure form returns a lazy proxy instead: the closure, not the
     * constructor, produces the instance, and it runs on first touch rather
     * than on resolution.
     *
     * The target must be a concrete class either way — a lazy instance has to
     * be an instance of something.
     *
     * Deliberately not on ContainerInterface — 1.x promises no method will be
     * added there. It moves onto the interface in 2.0.
     */
    public function lazy(string $abstract, string|callable|null $concrete = null): void
    {
        if ($concrete === null || is_string($concrete)) {
            $target = $this->assertLazyTarget($abstract, $concrete ?? $abstract);

            if ($concrete !== null) {
                $this->bind($abstract, $target);
            }

            $this->cacheManager->markAsLazy($target);
            $this->pushLazyRegistrations();
            return;
        }

        // Nothing but $abstract names a class here, so it is the only thing a
        // proxy can be an instance of.
        $target = $this->assertLazyTarget($abstract, $abstract);

        $this->bind($target, $concrete);
        $this->cacheManager->markAsLazyFactory($target, Closure::fromCallable($concrete));
        $this->pushLazyRegistrations();
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

        // Last, and deliberately so: the probe is the only branch that can
        // reflect, and routing it through the plan must not make a bound
        // abstract start building one.
        return $this->cacheManager->isInstantiable($id);
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

        $dependencies = $this->cacheManager->resolveCallableDependencies($callableKey, $callable, $parameters);

        return $callable(...$dependencies);
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
     * Pass a map to give the entries keys, which is what a command bus, a
     * router or a strategy map actually asks a tag for:
     *
     * ```php
     * $container->tag(['email' => EmailHandler::class, 'sms' => SmsHandler::class], 'handlers');
     * ```
     *
     * A keyed entry is addressable with taggedByKey() and replaces whatever was
     * under that key, so per-environment layering is a matter of registration
     * order. An id passed without a key is appended and deduped, exactly as
     * before. The two kinds live in one tag and iterate together.
     *
     * @param string|array<array-key, string> $ids
     */
    #[Override]
    public function tag(string|array $ids, string $tag): void
    {
        $this->tagRegistry()->tag($ids, $tag);
    }

    /**
     * Lazily resolve every service registered under a tag, in insertion order.
     *
     * Keyed entries are yielded under their key, unkeyed ones under their
     * position — so `foreach` is unchanged, and `iterator_to_array()` on a tag
     * registered as a map hands back the map, resolved.
     *
     * @return iterable<array-key, mixed>
     */
    #[Override]
    public function tagged(string $tag): iterable
    {
        foreach ($this->taggedIds($tag) as $key => $id) {
            yield $key => $this->get($id);
        }
    }

    /**
     * Resolve the one service registered under $key in $tag.
     *
     * Resolution stays lazy in the way that matters: registering a hundred
     * handlers builds none of them, and this builds exactly the one asked for.
     * The instance comes from the container's own cache, so a singleton still
     * lives in exactly one place — a keyed tag is a lookup table of ids, never
     * a second place instances are kept.
     *
     * An unknown key throws rather than returning null: a router asking for a
     * handler it has no entry for is a misconfiguration, and the exception
     * names the keys the tag does have. Ask taggedKeys() when the key is
     * genuinely optional.
     *
     * Deliberately not on ContainerInterface — 1.x promises no method will be
     * added there. It moves onto the interface in 2.0.
     */
    public function taggedByKey(string $tag, string $key): mixed
    {
        $ids = $this->taggedIds($tag);

        if (!isset($ids[$key])) {
            throw ContainerException::unknownTagKey($tag, $key, self::stringKeys($ids));
        }

        return $this->get($ids[$key]);
    }

    /**
     * The keys $tag can be asked for, in insertion order. Entries registered
     * without a key are not listed: there is no key to ask with.
     *
     * Deliberately not on ContainerInterface — 1.x promises no method will be
     * added there. It moves onto the interface in 2.0.
     *
     * @return list<string>
     */
    public function taggedKeys(string $tag): array
    {
        return self::stringKeys($this->taggedIds($tag));
    }

    /**
     * @param class-string $className
     *
     * @return list<string>
     */
    #[Override]
    public function getDependencyTree(string $className): array
    {
        return $this->dependencyTreeAnalyzer()->analyze($className);
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
     * Order does not matter relative to createScope(): a binding defined after
     * a scope exists is handed to that scope, and to its scopes, unless one of
     * them defined the same binding for itself — in which case its own wins,
     * the way shadowing works everywhere else. Without that, a late when() was
     * silently invisible to the containers created before it, which is a wrong
     * object injected with nothing to say so.
     *
     * @param class-string|list<class-string> $concrete
     */
    #[Override]
    public function when(string|array $concrete): ContextualBindingBuilder
    {
        $builder = new ContextualBindingBuilder(
            $this->contextualBindings,
            function (string $concrete, string $needs, mixed $implementation): void {
                /** @psalm-suppress PropertyTypeCoercion */
                $this->ownContextualBindings[$concrete][$needs] = $implementation;
                $this->pushContextualBinding($concrete, $needs, $implementation);
            },
        );
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
     * A key is the one thing a scope can override: re-registering 'email' on
     * the scope shadows the parent's entry for that scope alone, which is the
     * same rule bindings follow. Unkeyed ids merge by value as they always did.
     *
     * @return array<array-key, string>
     */
    private function taggedIds(string $tag): array
    {
        $own = $this->tagRegistry()->idsFor($tag);

        if ($this->parent === null) {
            return $own;
        }

        $merged = $this->parent->taggedIds($tag);

        foreach ($own as $key => $id) {
            if (!is_int($key)) {
                $merged[$key] = $id;

                continue;
            }

            if (!in_array($id, $merged, true)) {
                $merged[] = $id;
            }
        }

        return $merged;
    }

    /**
     * Keep a weak handle on a scope, so late configuration can reach it.
     *
     * Swept rather than checked on every creation, and the threshold grows with
     * the number of live scopes, so remembering a scope stays the constant cost
     * createScope() promises even in a runtime that makes one per request.
     */
    private function rememberScope(self $scope): void
    {
        if (count($this->scopes) >= $this->sweepScopesAt) {
            $this->liveScopes();
            $this->sweepScopesAt = max(self::SCOPE_SWEEP_MIN, count($this->scopes) * 2);
        }

        $this->scopes[] = WeakReference::create($scope);
    }

    /**
     * The scopes of this container that are still alive, dropping the handles
     * of those that are not. Weak, so a scope is collected when it goes out of
     * scope exactly as it was before its parent kept a handle at all.
     *
     * @return list<self>
     */
    private function liveScopes(): array
    {
        $live = [];
        $handles = [];

        foreach ($this->scopes as $handle) {
            $scope = $handle->get();

            if ($scope === null) {
                continue;
            }

            $live[] = $scope;
            $handles[] = $handle;
        }

        $this->scopes = $handles;

        return $live;
    }

    /**
     * Hand a binding defined after the fact to the scopes that took a copy of
     * this container's map, and to theirs.
     *
     * A scope that defined the same binding itself keeps it: that is its own
     * configuration, not a stale copy of this one's.
     */
    private function pushContextualBinding(string $concrete, string $needs, mixed $implementation): void
    {
        foreach ($this->liveScopes() as $scope) {
            if (isset($scope->ownContextualBindings[$concrete][$needs])) {
                continue;
            }

            /** @psalm-suppress PropertyTypeCoercion */
            $scope->contextualBindings[$concrete][$needs] = $implementation;
            $scope->pushContextualBinding($concrete, $needs, $implementation);
        }
    }

    /**
     * Same, for generated factories. A stale factory map costs an optimisation
     * rather than correctness — a generated `new` is only ever emitted for a
     * class nobody has bound — but a scope silently missing the fast path its
     * parent was given is still surprising.
     *
     * @param array<class-string, callable(): object> $factories
     */
    private function pushCompiledFactories(array $factories): void
    {
        foreach ($this->liveScopes() as $scope) {
            // Union rather than assignment: anything the scope installed for
            // itself outranks what arrives from above.
            $scope->compiledFactories += $factories;
            $scope->pushCompiledFactories($factories);
        }
    }

    /**
     * Same again, for lazy() registrations, which a scope also copies. A
     * missing one constructs eagerly: unobservable apart from the timing, which
     * is the entire reason lazy() was called.
     */
    private function pushLazyRegistrations(): void
    {
        foreach ($this->liveScopes() as $scope) {
            $scope->cacheManager->adoptLazyFrom($this->cacheManager);
            $scope->pushLazyRegistrations();
        }
    }

    /**
     * @param array<array-key, string> $ids
     *
     * @return list<string>
     */
    private static function stringKeys(array $ids): array
    {
        $keys = [];

        foreach ($ids as $key => $_) {
            if (!is_int($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
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

    /**
     * @param list<class-string> $classNames
     */
    private function compilerFor(array $classNames): ContainerCompiler
    {
        return new ContainerCompiler(
            $this->compile($classNames),
            $this->getBindings(),
            $this->cacheManager->lazyClasses(),
        );
    }

    /**
     * @return class-string the target, proven buildable
     */
    private function assertLazyTarget(string $abstract, string $target): string
    {
        if (!$this->cacheManager->isInstantiable($target)) {
            throw ContainerException::lazyTargetNotConcrete($abstract, $target);
        }

        /** @var class-string */
        return $target;
    }

    private function tagRegistry(): TagRegistry
    {
        return $this->tagRegistry ??= new TagRegistry();
    }

    private function dependencyTreeAnalyzer(): DependencyTreeAnalyzer
    {
        return $this->dependencyTreeAnalyzer ??= new DependencyTreeAnalyzer($this->bindingResolver);
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
        if ($this->compiledFactories !== [] && !$this->runtimeOwns($class)) {
            $factory = $this->compiledFactories[$class] ?? null;

            if ($factory !== null) {
                return $factory();
            }
        }

        return $this->bindingResolver->resolve($class, $this->cacheManager);
    }

    /**
     * Whether a registration on this container decides how $class is built, in
     * which case a generated `new` expression must not.
     *
     * The generator refuses to emit anything for a bound class or one carrying a
     * lifetime attribute, so the file it writes agrees with the container it was
     * written from. It cannot speak for the container it is installed *into*:
     * `bind()` there resolved to the generated class rather than the bound one,
     * and `singleton()` built a fresh instance per get() while the application
     * believed the service was shared. Neither said anything.
     *
     * Only asked once a factory map is installed, so a container without one
     * pays nothing.
     */
    private function runtimeOwns(string $class): bool
    {
        return isset($this->bindings[$class]) || $this->cacheManager->ownsSingleton($class);
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
