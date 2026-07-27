<?php

declare(strict_types=1);

namespace Gacela\Container;

use Closure;
use Gacela\Container\Attribute\Factory;
use Gacela\Container\Attribute\Lazy;
use Gacela\Container\Attribute\Singleton;
use Gacela\Container\Exception\ContainerException;
use ReflectionClass;

use function class_exists;
use function count;
use function method_exists;

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

    /** @var array<class-string, array{singleton: bool, factory: bool, lazy: bool}> */
    private array $attributeCache = [];

    /**
     * Whether the runtime can build lazy ghosts (PHP 8.4+). On older runtimes
     * #[Lazy] classes are constructed eagerly, which is unobservable apart
     * from the timing.
     */
    private static ?bool $supportsLazyObjects = null;

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
     * Build the instance, deferring the constructor when the class is #[Lazy].
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

        if ($this->isLazy($class)) {
            return $this->newLazyGhost($class);
        }

        $dependencies = $resolver->resolveDependencies($class);

        /** @psalm-suppress MixedMethodCall */
        $instance = new $class(...$dependencies);

        if (self::$hasInjectedProps[$class] ??= $resolver->hasInjectedProperties($class)) {
            $resolver->injectPropertiesOn($instance, $class);
        }

        return $instance;
    }

    /**
     * A real instance of $class whose constructor runs on first use.
     *
     * Dependencies are resolved inside the initializer, not before it, so a
     * lazy service that is never touched costs nothing to build.
     *
     * @param class-string $class
     */
    private function newLazyGhost(string $class): object
    {
        $reflection = new ReflectionClass($class);

        /**
         * newLazyGhost() is PHP 8.4+, and the analysers target the 8.3 floor,
         * so they cannot see it. isLazy() gates this behind a runtime
         * capability check, which is exactly what they cannot model.
         *
         * Calling __construct() directly on the ghost is the documented way to
         * initialize one, not an accident.
         *
         * @psalm-suppress UndefinedMethod, MixedReturnStatement, MixedInferredReturnType
         *
         * @phpstan-ignore method.notFound, return.type
         */
        return $reflection->newLazyGhost(function (object $instance) use ($class): void {
            $resolver = $this->getDependencyResolver();
            $dependencies = $resolver->resolveDependencies($class);

            /**
             * @psalm-suppress MixedMethodCall, DirectConstructorCall
             *
             * @phpstan-ignore method.notFound
             */
            $instance->__construct(...$dependencies);

            // Deferred with the constructor, not run eagerly at ghost creation:
            // resolving them up front would defeat the point of #[Lazy].
            if (self::$hasInjectedProps[$class] ??= $resolver->hasInjectedProperties($class)) {
                $resolver->injectPropertiesOn($instance, $class);
            }
        });
    }

    /**
     * @param class-string $class
     */
    private function isLazy(string $class): bool
    {
        self::$supportsLazyObjects ??= method_exists(ReflectionClass::class, 'newLazyGhost');

        return self::$supportsLazyObjects && $this->attributesOf($class)['lazy'];
    }

    private function getDependencyResolver(): DependencyResolver
    {
        if ($this->dependencyResolver === null) {
            $this->dependencyResolver = new DependencyResolver(
                $this->bindings,
                $this->contextualBindings,
                $this->planRegistry,
                $this->container,
            );

            if ($this->parent !== null) {
                $this->dependencyResolver->inheritFrom($this->parent);
            }
        }

        return $this->dependencyResolver;
    }

    /**
     * Every class attribute the container cares about, read in one reflection
     * pass and memoized per class.
     *
     * Looking each attribute up separately meant rebuilding a concatenated
     * cache key on every instantiation, which is squarely on the hot path.
     *
     * @param class-string $class
     *
     * @return array{singleton: bool, factory: bool, lazy: bool}
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
            'lazy' => $reflection->getAttributes(Lazy::class) !== [],
        ];
    }
}
