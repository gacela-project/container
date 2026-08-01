<?php

declare(strict_types=1);

namespace Gacela\Container;

use Closure;
use Gacela\Container\Attribute\Factory;
use Gacela\Container\Attribute\Singleton;
use Gacela\Container\Exception\ContainerException;
use ReflectionAttribute;
use ReflectionClass;
use WeakReference;

use function class_exists;
use function count;

/**
 * Resolves dependencies (delegating reflection caching to the resolver),
 * keeps singleton instances, and caches attribute lookups.
 *
 * @psalm-import-type BindingsMap from ContainerInterface
 * @psalm-import-type ContextualBindingsMap from ContainerInterface
 * @psalm-import-type CompiledPlans from DependencyResolver
 *
 * @internal
 * Not covered by backward compatibility: this class is an implementation
 * detail of Container and may change or disappear in any release
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

    /**
     * The lifetime attributes of a class, shared across containers.
     *
     * Whether a class carries #[Singleton] or #[Factory] is read off its
     * definition, which cannot change within a process, so there is nothing
     * container-specific in the answer — held per instance, every container
     * reflected every class it resolved, which is the tax PlanCache exists to
     * remove on the plan axis being paid again on this one.
     *
     * @var array<class-string, array{singleton: bool, factory: bool}>
     */
    private static array $attributeCache = [];

    /**
     * Classes already proven instantiable, shared across containers.
     *
     * A loaded class definition cannot change within a process, so a positive
     * is permanent and safe to share. The negative is deliberately not stored:
     * class_exists() can start answering true later, and the miss path throws
     * anyway, so there is nothing to gain by making it fast.
     *
     * @var array<class-string, true>
     */
    private static array $instantiable = [];

    /**
     * Whether a class has #[Inject] properties, so the resolver is not asked
     * once per instantiation only to answer "no".
     *
     * Shared across containers for the same reason the resolver shares its
     * property plans: it is a memo of a class definition, which cannot change
     * within a process.
     *
     * @var array<class-string, bool>
     */
    private static array $hasInjectedProps = [];

    /**
     * The same, for #[Inject] methods. See $hasInjectedProps.
     *
     * @var array<class-string, bool>
     */
    private static array $hasInjectedMethods = [];

    /** @var array<class-string, true> Classes forced to behave as singletons at runtime */
    private array $forcedSingletons = [];

    private ?DependencyResolver $dependencyResolver = null;

    private ?Container $parent = null;

    private PlanRegistry $planRegistry;

    /** @var array<class-string, true> Classes made lazy through Container::lazy() */
    private array $lazyClasses = [];

    /** @var array<class-string, Closure> Lazy targets whose instance a closure produces */
    private array $lazyFactories = [];

    /**
     * @param BindingsMap $bindings
     * @param ContextualBindingsMap $contextualBindings
     * @param CompiledPlans $compiledPlans
     * @param WeakReference<ContainerInterface>|null $containerRef weak on purpose; see Container::__construct()
     * @param PlanCache|null $planCache a cache shared with unrelated containers
     */
    public function __construct(
        private array &$bindings = [],
        private array &$contextualBindings = [],
        array $compiledPlans = [],
        private ?WeakReference $containerRef = null,
        ?PlanCache $planCache = null,
    ) {
        if ($planCache === null) {
            $this->planRegistry = new PlanRegistry($compiledPlans);

            return;
        }

        $this->planRegistry = $planCache->registry();

        // A plan already in the shared cache was built by reflection in this
        // process, so it describes the class as loaded. A compiled one came
        // off disk and may not. Seeding around what is already there keeps the
        // authoritative answer.
        $this->planRegistry->plans += $compiledPlans;
    }

    /**
     * Drop the caches that outlive every container.
     *
     * All three are memos of a class definition — stable while the set of
     * loadable classes is fixed, which is every production request and not a
     * process that generates code, warms a cache, or re-bootstraps between jobs.
     * See Container::resetStaticCaches(), the supported way in.
     */
    public static function resetCache(): void
    {
        self::$instantiable = [];
        self::$hasInjectedProps = [];
        self::$hasInjectedMethods = [];
        self::$attributeCache = [];
    }

    /**
     * Wire this manager as a scope of $parent's.
     *
     * Must run before anything resolves through this manager: the resolver is
     * handed the plan registry it finds at the time it is built. Singleton
     * instances are deliberately not shared — those are what a scope exists to
     * keep separate.
     */
    public function inheritFrom(self $parentManager, Container $parent): void
    {
        $this->parent = $parent;
        $this->planRegistry = $parentManager->planRegistry;

        // Copied rather than chained, like Container does with contextual
        // bindings and for the same reason: the lazy test runs on the hot path
        // of every nested parameter, and a walk up the chain there would make a
        // scope's resolutions scale with the depth of its ancestry. A lazy()
        // call made on the parent afterwards is pushed down instead — see
        // adoptLazyFrom().
        $this->lazyClasses = $parentManager->lazyClasses;
        $this->lazyFactories = $parentManager->lazyFactories;
    }

    /**
     * Take the registrations the parent has now, without disturbing the ones
     * this manager was given for itself: a scope that made a class lazy on its
     * own terms outranks whatever arrives from above.
     */
    public function adoptLazyFrom(self $parentManager): void
    {
        $this->lazyClasses += $parentManager->lazyClasses;
        $this->lazyFactories += $parentManager->lazyFactories;
    }

    /**
     * Constructor plans gathered so far, for persisting to a compiled cache.
     *
     * @return CompiledPlans
     */
    public function exportCompiledPlans(): array
    {
        return $this->planRegistry->plans;
    }

    /**
     * Whether this manager already owns a singleton for $class, or has been
     * told to treat it as one.
     */
    public function ownsSingleton(string $class): bool
    {
        return isset($this->singletonInstances[$class]) || isset($this->forcedSingletons[$class]);
    }

    /**
     * @param class-string $class
     */
    public function markAsSingleton(string $class): void
    {
        $this->forcedSingletons[$class] = true;
    }

    /**
     * @param class-string $class
     */
    public function markAsLazy(string $class): void
    {
        $this->lazyClasses[$class] = true;
    }

    /**
     * @param class-string $class
     */
    public function markAsLazyFactory(string $class, Closure $factory): void
    {
        $this->lazyClasses[$class] = true;
        $this->lazyFactories[$class] = $factory;
    }

    /**
     * A lazy proxy for a class whose instance a lazy() closure produces, or null
     * when there is no such registration.
     *
     * Lets BindingResolver hold off on invoking a callable binding: doing it
     * there is what makes every closure binding eager.
     *
     * @param class-string $class
     */
    public function lazyProxyFor(string $class): ?object
    {
        if (!isset($this->lazyFactories[$class])) {
            return null;
        }

        $resolver = $this->getDependencyResolver();

        // On PHP 8.3 there is nothing to build a proxy with, so the closure
        // binding stays eager and BindingResolver invokes it as usual.
        return $resolver->isLazy($class)
            ? $resolver->newLazyInstance($class)
            : null;
    }

    /**
     * @return array<class-string, true>
     */
    public function lazyClasses(): array
    {
        return $this->lazyClasses;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return list<mixed>
     */
    public function resolveCallableDependencies(string $callableKey, callable $callable, array $overrides = []): array
    {
        $this->resolvedKeys[$callableKey] = true;

        return $this->getDependencyResolver()->resolveCallableDependencies($callable, $overrides);
    }

    /**
     * Build a fresh instance, overriding constructor arguments by parameter name.
     * Overrides are never cached.
     *
     * @param class-string $class
     * @param array<string, mixed> $overrides
     */
    public function instantiateWith(string $class, array $overrides): object
    {
        $this->resolvedKeys[$class] = true;

        $resolver = $this->getDependencyResolver();
        $dependencies = $resolver->resolveDependencies($class, $overrides);

        /** @psalm-suppress MixedMethodCall */
        $instance = new $class(...$dependencies);

        if (self::$hasInjectedProps[$class] ??= $resolver->hasInjectedProperties($class)) {
            $resolver->injectPropertiesOn($instance, $class);
        }

        // After the constructor and after the properties — the documented order.
        if (self::$hasInjectedMethods[$class] ??= $resolver->hasInjectedMethods($class)) {
            $resolver->callInjectedMethodsOn($instance, $class);
        }

        return $instance;
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
     * Change what closures are handed, now and for the resolver built later.
     * See Container::withSelfReference().
     *
     * @param WeakReference<ContainerInterface> $containerRef
     */
    public function useSelfReference(WeakReference $containerRef): void
    {
        $this->containerRef = $containerRef;
        $this->dependencyResolver?->useSelfReference($containerRef);
    }

    /**
     * See DependencyResolver::dropArgBuilders().
     */
    public function dropArgBuilders(): void
    {
        $this->dependencyResolver?->dropArgBuilders();
    }

    /**
     * Plan $classNames and everything their constructors reach, constructing
     * nothing. See DependencyResolver::planDeep() for why compiling must not
     * resolve.
     *
     * @param list<class-string> $classNames
     */
    public function planAll(array $classNames): void
    {
        $resolver = $this->getDependencyResolver();
        $seen = [];

        foreach ($classNames as $className) {
            $resolver->planDeep($className, $seen);
        }
    }

    /**
     * @param class-string $class
     */
    public function instantiate(string $class): object
    {
        $attributes = $this->attributesOf($class);

        if (isset($this->forcedSingletons[$class]) || $attributes['singleton']) {
            if (isset($this->singletonInstances[$class])) {
                return $this->singletonInstances[$class];
            }

            $instance = $this->createInstance($class);
            $this->singletonInstances[$class] = $instance;
            return $instance;
        }

        if ($attributes['factory']) {
            // Not recorded as resolved: a #[Factory] class is built afresh every
            // time, so it is never one of the cached dependencies stats() counts.
            return $this->construct($class);
        }

        return $this->createInstance($class);
    }

    public function getCacheSize(): int
    {
        return count($this->resolvedKeys);
    }

    /**
     * Whether $class can be instantiated, answered once per class per process.
     *
     * The resolver answers off the class plan rather than reflecting again, so
     * a caller asking this before resolving — has(), lazy() — warms the very
     * plan the following get() needs instead of building a ReflectionClass the
     * plan registry already holds or is about to.
     *
     * The memo is read before class_exists() because a repeated has() on an
     * autowirable class is the hot shape, and that ordering keeps it at one
     * array lookup. The guard still runs before the resolver, which needs a
     * loaded class; its negative is not stored, since class_exists() can start
     * answering true later in the same process.
     */
    public function isInstantiable(string $class): bool
    {
        if (isset(self::$instantiable[$class])) {
            return true;
        }

        if (!class_exists($class) || !$this->getDependencyResolver()->isInstantiable($class)) {
            return false;
        }

        return self::$instantiable[$class] = true;
    }

    /**
     * @param class-string $class
     */
    private function createInstance(string $class): object
    {
        $this->resolvedKeys[$class] = true;

        return $this->construct($class);
    }

    /**
     * Build the instance, deferring the constructor when the class is lazy.
     *
     * @param class-string $class
     */
    private function construct(string $class): object
    {
        $resolver = $this->getDependencyResolver();

        // has() already reports these as unresolvable; without this guard get()
        // disagreed by emitting a raw PHP Error from inside the container.
        if (!$this->isInstantiable($class)) {
            throw ContainerException::classNotInstantiable($class);
        }

        if ($resolver->isLazy($class)) {
            return $resolver->newLazyInstance($class);
        }

        // Straight to `new` when the whole graph below this class is settled by
        // the constructors alone. Deliberately here rather than earlier: the
        // lifetime, the instance registry and the compiled factories have all
        // had their say by now, so this only ever replaces the *construction*.
        //
        // Read inline rather than through argBuilderFor(), because once a class
        // is decided — a builder or a refusal — that call is a method call per
        // construction to learn something already known, and at ~1μs a
        // resolution that is measurable on its own (#181). The call is made
        // only while the answer is still open, which is twice per class.
        $builder = $resolver->argBuilders[$class] ?? null;

        if ($builder instanceof Closure) {
            /** @var object */
            return $builder();
        }

        if ($builder === null) {
            // First construction of this class: record the sighting inline.
            // Composing is deferred to the second one (see argBuilderFor()),
            // and a container that builds the class once — a fresh container
            // per request — must not pay even the call to find that out.
            $resolver->argBuilders[$class] = true;
        } elseif ($builder === true) {
            $builder = $resolver->argBuilderFor($class);

            if ($builder !== null) {
                /** @var object */
                return $builder();
            }
        }

        $dependencies = $resolver->resolveDependencies($class);

        /** @psalm-suppress MixedMethodCall */
        $instance = new $class(...$dependencies);

        if (self::$hasInjectedProps[$class] ??= $resolver->hasInjectedProperties($class)) {
            $resolver->injectPropertiesOn($instance, $class);
        }

        // After the constructor and after the properties — the documented order.
        if (self::$hasInjectedMethods[$class] ??= $resolver->hasInjectedMethods($class)) {
            $resolver->callInjectedMethodsOn($instance, $class);
        }

        return $instance;
    }

    private function getDependencyResolver(): DependencyResolver
    {
        if ($this->dependencyResolver === null) {
            $this->dependencyResolver = new DependencyResolver(
                $this->bindings,
                $this->contextualBindings,
                $this->planRegistry,
                $this->containerRef,
                $this->lazyClasses,
                $this->lazyFactories,
            );

            if ($this->parent !== null) {
                $this->dependencyResolver->inheritFrom($this->parent);
            }
        }

        return $this->dependencyResolver;
    }

    /**
     * The lifetime attributes of a class, read in one reflection pass and
     * memoized per class.
     *
     * Looking each attribute up separately meant rebuilding a concatenated
     * cache key on every instantiation, which is squarely on the hot path.
     *
     * #[Lazy] is deliberately absent: the resolver memoizes it process-wide,
     * because nested resolution has to answer the same question without ever
     * reaching this manager.
     *
     * @param class-string $class
     *
     * @return array{singleton: bool, factory: bool}
     */
    private function attributesOf(string $class): array
    {
        $flags = self::$attributeCache[$class] ?? null;
        if ($flags !== null) {
            return $flags;
        }

        $reflection = new ReflectionClass($class);

        return self::$attributeCache[$class] = [
            'singleton' => $reflection->getAttributes(Singleton::class, ReflectionAttribute::IS_INSTANCEOF) !== [],
            'factory' => $reflection->getAttributes(Factory::class, ReflectionAttribute::IS_INSTANCEOF) !== [],
        ];
    }
}
