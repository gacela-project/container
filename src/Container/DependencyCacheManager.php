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

    /** @var array<class-string, bool> */
    private array $instantiableCache = [];

    /**
     * Whether a class has #[Inject] properties, so the resolver is not asked
     * once per instantiation only to answer "no".
     *
     * @var array<class-string, bool>
     */
    private array $hasInjectedProps = [];

    /** @var array<class-string, true> Classes forced to behave as singletons at runtime */
    private array $forcedSingletons = [];

    private ?DependencyResolver $dependencyResolver = null;

    /**
     * @param BindingsMap $bindings
     * @param ContextualBindingsMap $contextualBindings
     * @param CompiledPlans $compiledPlans
     */
    public function __construct(
        private array &$bindings = [],
        private array &$contextualBindings = [],
        private array $compiledPlans = [],
        private ?ContainerInterface $container = null,
    ) {
    }

    /**
     * Constructor plans gathered so far, for persisting to a compiled cache.
     *
     * @return CompiledPlans
     */
    public function exportCompiledPlans(): array
    {
        return $this->getDependencyResolver()->exportPlans();
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

        if ($this->hasInjectedProps[$class] ??= $resolver->hasInjectedProperties($class)) {
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
        // has() already reports these as unresolvable; without this guard get()
        // disagreed by emitting a raw PHP Error from inside the container.
        if (!$this->isInstantiable($class)) {
            throw ContainerException::classNotInstantiable($class);
        }

        if ($this->isLazy($class)) {
            return $this->newLazyGhost($class);
        }

        $resolver = $this->getDependencyResolver();
        $dependencies = $resolver->resolveDependencies($class);

        /** @psalm-suppress MixedMethodCall */
        $instance = new $class(...$dependencies);

        if ($this->hasInjectedProps[$class] ??= $resolver->hasInjectedProperties($class)) {
            $resolver->injectPropertiesOn($instance, $class);
        }

        return $instance;
    }

    /**
     * @param class-string $class
     */
    private function isInstantiable(string $class): bool
    {
        return $this->instantiableCache[$class] ??= class_exists($class)
            && (new ReflectionClass($class))->isInstantiable();
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
            if ($this->hasInjectedProps[$class] ??= $resolver->hasInjectedProperties($class)) {
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
                $this->compiledPlans,
                $this->container,
            );
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
