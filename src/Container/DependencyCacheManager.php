<?php

declare(strict_types=1);

namespace Gacela\Container;

use Closure;
use Gacela\Container\Attribute\Factory;
use Gacela\Container\Attribute\Singleton;
use Gacela\Container\Exception\ContainerException;
use ReflectionClass;

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

    /** @var array<class-string, array{singleton: bool, factory: bool}> */
    private array $attributeCache = [];

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
     */
    public function __construct(
        private array &$bindings = [],
        private array &$contextualBindings = [],
        array $compiledPlans = [],
        private ?ContainerInterface $container = null,
    ) {
        $this->planRegistry = new PlanRegistry($compiledPlans);
    }

    /**
     * Drop the caches that outlive every container.
     *
     * Both are memos of a class definition — stable while the set of loadable
     * classes is fixed, which is every production request and not a process
     * that generates code, warms a cache, or re-bootstraps between jobs. See
     * Container::resetStaticCaches(), the supported way in.
     */
    public static function resetCache(): void
    {
        self::$instantiable = [];
        self::$hasInjectedProps = [];
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
        // call on the parent after the scope exists is therefore not visible
        // to it.
        $this->lazyClasses = $parentManager->lazyClasses;
        $this->lazyFactories = $parentManager->lazyFactories;
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
    public function resolveCallableDependencies(string $callableKey, Closure $callable, array $overrides = []): array
    {
        $this->resolvedKeys[$callableKey] = true;

        return $this->getDependencyResolver()->resolveDependencies($callable, $overrides);
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
            // Don't cache dependencies for factory classes to ensure fresh instances
            return $this->construct($class);
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
        if (!isset(self::$instantiable[$class])) {
            if (!$resolver->isInstantiable($class)) {
                throw ContainerException::classNotInstantiable($class);
            }

            self::$instantiable[$class] = true;
        }

        if ($resolver->isLazy($class)) {
            return $resolver->newLazyInstance($class);
        }

        $dependencies = $resolver->resolveDependencies($class);

        /** @psalm-suppress MixedMethodCall */
        $instance = new $class(...$dependencies);

        if (self::$hasInjectedProps[$class] ??= $resolver->hasInjectedProperties($class)) {
            $resolver->injectPropertiesOn($instance, $class);
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
                $this->container,
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
        $flags = $this->attributeCache[$class] ?? null;
        if ($flags !== null) {
            return $flags;
        }

        $reflection = new ReflectionClass($class);

        return $this->attributeCache[$class] = [
            'singleton' => $reflection->getAttributes(Singleton::class) !== [],
            'factory' => $reflection->getAttributes(Factory::class) !== [],
        ];
    }
}
