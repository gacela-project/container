<?php

declare(strict_types=1);

namespace Gacela\Container;

use Closure;
use Gacela\Container\Attribute\Inject;
use Gacela\Container\Attribute\Lazy;
use Gacela\Container\Exception\CircularDependencyException;
use Gacela\Container\Exception\DependencyInvalidArgumentException;
use Gacela\Container\Exception\DependencyNotFoundException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use WeakMap;

use function array_key_exists;
use function array_keys;
use function class_exists;
use function count;
use function is_callable;
use function is_object;
use function is_string;
use function method_exists;

/**
 * @psalm-import-type BindingsMap from ContainerInterface
 * @psalm-import-type ContextualBindingsMap from ContainerInterface
 *
 * @psalm-type ParamPlan = array{name: string, hasType: bool, type: string|null, isScalar: bool, inject: class-string|null, hasDefault: bool, default: mixed, declaringClass: string|null}
 * @psalm-type PropPlan = array{name: string, hasType: bool, type: string|null, isScalar: bool, inject: class-string|null, isReadonly: bool, declaringClass: class-string}
 * @psalm-type ClassPlan = array{instantiable: bool, params: list<ParamPlan>, props: list<PropPlan>}
 * @psalm-type StoredClassPlan = array{instantiable: bool, params: list<ParamPlan>, props?: list<PropPlan>}
 * @psalm-type CompiledPlans = array<class-string, StoredClassPlan>
 *
 * @internal
 * Not covered by backward compatibility: this class is an implementation
 * detail of Container and may change or disappear in any release
 */
final class DependencyResolver
{
    /** @var array<class-string, bool> */
    private array $resolvingStack = [];

    /** @var array<string, bool> Memoized class_exists()/interface_exists() checks */
    private array $typeExistsCache = [];

    /** @var list<class-string> */
    private array $buildStack = [];

    /**
     * Property plans keyed by class, shared across containers.
     *
     * A class definition cannot change within a process, so this is a pure memo
     * of reflection output. Sharing it keeps the scan off the cold-start path:
     * without it every new container would re-reflect every class it touches,
     * taxing users who have no #[Inject] properties at all.
     *
     * @var array<class-string, list<PropPlan>>
     */
    private static array $propertyPlans = [];

    /**
     * Whether a class carries #[Lazy], memoized process-wide.
     *
     * Read off a class definition, which cannot change within a process, so it
     * is shared for the same reason the property plans are. Deliberately not
     * part of the class plan: a #[Lazy] class must be answered *without*
     * describing its constructor, which is the work laziness exists to defer.
     *
     * @var array<class-string, bool>
     */
    private static array $lazyAttribute = [];

    /** @var array<string, ReflectionProperty> */
    private array $propertyHandles = [];

    /**
     * Parameter plans of callables that have a name to key on: a function, a
     * `Class::method`, an invokable's `__invoke`.
     *
     * @var array<string, list<ParamPlan>>
     */
    private array $callablePlans = [];

    /**
     * The same for closures, which have none. Built on first use, since most
     * containers never resolve one.
     *
     * @var WeakMap<Closure, list<ParamPlan>>|null
     */
    private ?WeakMap $closurePlans = null;

    private ?Container $parent = null;

    /**
     * Hoisted out of $parent so the fall-through test costs a bool read on the
     * hot path. Every unbound constructor parameter of every graph reaches it,
     * and for a container with no parent — nearly all of them — the answer is
     * fixed for the resolver's whole life.
     */
    private bool $hasParent = false;

    /**
     * Whether the runtime can build native lazy objects (PHP 8.4+). On older
     * runtimes lazy targets are constructed eagerly, which is unobservable
     * apart from the timing.
     *
     * Hoisted into a field for the same reason $hasParent is: every nested
     * parameter of every graph tests it.
     */
    private bool $supportsLazyObjects;

    private static ?bool $lazyObjectsAvailable = null;

    /**
     * @param BindingsMap $bindings
     * @param ContextualBindingsMap $contextualBindings
     * @param array<class-string, true> $lazyClasses classes made lazy through Container::lazy()
     * @param array<class-string, Closure> $lazyFactories lazy targets whose instance a closure produces
     */
    public function __construct(
        private array &$bindings = [],
        private array &$contextualBindings = [],
        private PlanRegistry $planRegistry = new PlanRegistry(),
        private ?ContainerInterface $container = null,
        private array &$lazyClasses = [],
        private array &$lazyFactories = [],
    ) {
        $this->supportsLazyObjects = self::$lazyObjectsAvailable
            ??= method_exists(ReflectionClass::class, 'newLazyGhost');
    }

    /**
     * Drop the caches that outlive every container.
     *
     * $propertyPlans and $lazyAttribute are memos of a class definition, and
     * are the reason this exists. $lazyObjectsAvailable is a fact about the
     * running PHP binary, which no reset can change — it is cleared anyway so
     * that "every static is back at its declared default" holds without
     * exceptions, and the next resolver recomputes the same answer.
     *
     * See Container::resetStaticCaches(), the supported way in.
     */
    public static function resetCache(): void
    {
        self::$propertyPlans = [];
        self::$lazyAttribute = [];
        self::$lazyObjectsAvailable = null;
    }

    /**
     * Let a scope hand unresolved types to the container it was created from.
     */
    public function inheritFrom(Container $parent): void
    {
        $this->parent = $parent;
        $this->hasParent = true;
    }

    /**
     * @param class-string $toResolve
     * @param array<string, mixed> $overrides runtime values keyed by parameter name (top level only)
     *
     * @return list<mixed>
     */
    public function resolveDependencies(string $toResolve, array $overrides = []): array
    {
        // Track which class is being resolved for contextual bindings.
        $this->buildStack[] = $toResolve;

        try {
            return $this->resolveEntryParameters($this->describeClass($toResolve)['params'], $overrides);
        } finally {
            array_pop($this->buildStack);
        }
    }

    /**
     * @param array<string, mixed> $overrides runtime values keyed by parameter name
     *
     * @return list<mixed>
     */
    public function resolveCallableDependencies(callable $callable, array $overrides = []): array
    {
        return $this->resolveEntryParameters($this->describeCallable($callable), $overrides);
    }

    /**
     * Whether $className can be instantiated at all.
     *
     * Answered off the class plan, which resolveDependencies() builds a moment
     * later anyway. Reflecting separately for this made every cold get() pay
     * for two ReflectionClass instances where one already had the answer.
     *
     * @param class-string $className
     */
    public function isInstantiable(string $className): bool
    {
        return class_exists($className) && $this->describeClass($className)['instantiable'];
    }

    /**
     * Whether $className resolves to a lazy instance, whether it said so with
     * #[Lazy] or was registered through Container::lazy().
     *
     * @param class-string $className
     */
    public function isLazy(string $className): bool
    {
        if (!$this->supportsLazyObjects) {
            return false;
        }

        return isset($this->lazyClasses[$className])
            || (self::$lazyAttribute[$className] ??= (new ReflectionClass($className))->getAttributes(Lazy::class) !== []);
    }

    /**
     * A lazy instance of $className: a real object of that type whose
     * construction has not happened yet.
     *
     * A ghost when the constructor produces the instance, a proxy when a
     * lazy() factory does — the initializer of a ghost cannot replace the
     * object, and a factory returns a different one.
     *
     * @param class-string $className
     */
    public function newLazyInstance(string $className): object
    {
        $factory = $this->lazyFactories[$className] ?? null;

        return $factory === null
            ? $this->newLazyGhost($className)
            : $this->newLazyProxy($className, $factory);
    }

    /**
     * The compiled constructor plans gathered so far, for persisting to a cache.
     *
     * @return CompiledPlans
     */
    public function exportPlans(): array
    {
        return $this->planRegistry->plans;
    }

    /**
     * Whether $className declares any #[Inject] property.
     *
     * Lets a caller skip injectPropertiesOn() outright. Almost no class has
     * one, and a call per instantiation to be told "none" is measurable on the
     * shallow benchmarks.
     *
     * @param class-string $className
     */
    public function hasInjectedProperties(string $className): bool
    {
        return $this->describeProperties($className) !== [];
    }

    /**
     * Assign the #[Inject] properties of an instance built outside this class.
     *
     * Nested instances are handled by instantiateFromPlan(); this is the entry
     * point for the ones the cache manager constructs itself. Callers guard it
     * with hasInjectedProperties() rather than it short-circuiting internally,
     * so a class with nothing to inject costs no call at all.
     *
     * @param class-string $className
     */
    public function injectPropertiesOn(object $instance, string $className): void
    {
        $this->resolvingStack[$className] = true;

        try {
            $this->injectProperties($instance, $this->describeProperties($className));
        } finally {
            // The stack has to be unwound even when a property fails to
            // resolve, or the next resolution on this container reports a
            // resolution chain containing a class it never touched.
            unset($this->resolvingStack[$className]);
        }
    }

    /**
     * Entry-point parameters (top-level class or callable) must all be
     * resolvable; an untyped parameter is a hard error here.
     *
     * @param list<ParamPlan> $params
     * @param array<string, mixed> $overrides runtime values keyed by parameter name
     *
     * @return list<mixed>
     */
    private function resolveEntryParameters(array $params, array $overrides = []): array
    {
        /** @var list<mixed> $dependencies */
        $dependencies = [];

        foreach ($params as $param) {
            if (array_key_exists($param['name'], $overrides)) {
                /** @psalm-suppress MixedAssignment */
                $dependencies[] = $overrides[$param['name']];
                continue;
            }

            /** @psalm-suppress MixedAssignment */
            $dependencies[] = $this->resolveParameter($param);
        }

        return $dependencies;
    }

    /**
     * @param ParamPlan $param
     */
    private function resolveParameter(array $param): mixed
    {
        /** @psalm-suppress MixedAssignment */
        [$hasNamedBinding, $namedValue] = $this->resolveNamedContextualBinding($param);
        if ($hasNamedBinding) {
            return $namedValue;
        }

        if (!$param['hasType']) {
            throw DependencyInvalidArgumentException::noParameterTypeFor($param['name'], $this->getResolutionChain());
        }

        if ($param['isScalar'] && !$param['hasDefault']) {
            throw DependencyInvalidArgumentException::unableToResolve(
                $param['type'] ?? $param['name'],
                $param['declaringClass'] ?? '',
                $this->getResolutionChain(),
            );
        }

        if ($param['inject'] !== null) {
            return $this->resolveClass($param['inject']);
        }

        if ($param['hasDefault']) {
            return $param['default'];
        }

        /** @var class-string $type */
        $type = $param['type'];

        return $this->resolveClass($type);
    }

    /**
     * Resolve a contextual binding matched by the parameter name (e.g. `'$apiKey'`)
     * scoped to the parameter's declaring class.
     *
     * @param ParamPlan $param
     *
     * @return array{bool, mixed} [matched, value]
     */
    private function resolveNamedContextualBinding(array $param): array
    {
        if ($this->contextualBindings === []) {
            return [false, null];
        }

        $declaringClass = $param['declaringClass'];
        if ($declaringClass === null) {
            return [false, null];
        }

        $key = '$' . $param['name'];
        if (!isset($this->contextualBindings[$declaringClass][$key])) {
            return [false, null];
        }

        /** @var mixed $value */
        $value = $this->contextualBindings[$declaringClass][$key];
        if (is_callable($value)) {
            /** @psalm-suppress MixedFunctionCall */
            return [true, $value($this->container)];
        }

        return [true, $value];
    }

    /**
     * @param class-string $paramTypeName
     */
    private function resolveClass(string $paramTypeName): mixed
    {
        /** @psalm-suppress MixedAssignment */
        $contextualBinding = $this->getContextualBinding($paramTypeName);
        if ($contextualBinding !== null) {
            // Same ordering as BindingResolver::resolve(): a class-string is
            // the common case, so it never pays the function-table lookup
            // is_callable() does on a string — nor risks that lookup answering
            // true for a class whose name collides with a function's.
            if (!is_string($contextualBinding) && is_callable($contextualBinding)) {
                /** @psalm-suppress MixedFunctionCall */
                return $contextualBinding($this->container);
            }

            if (is_object($contextualBinding)) {
                return $contextualBinding;
            }

            // It's a class string - use it instead of the interface
            /** @var class-string $contextualBinding */
            $paramTypeName = $contextualBinding;
        }

        $bindClass = $this->bindings[$paramTypeName] ?? null;

        // An ancestor that already owns this type hands over its own instance,
        // so a scope shares it instead of building a second one.
        if ($this->hasParent && $bindClass === null && $this->parent?->provides($paramTypeName) === true) {
            return $this->parent->get($paramTypeName);
        }

        // The hottest of the three sites with this ordering: it runs once per
        // constructor parameter of every node of every graph. A class-string
        // binding is settled below, by the plan, so it never pays the
        // function-table lookup — nor risks that lookup answering true for a
        // class whose name a function shares.
        if (!is_string($bindClass) && is_callable($bindClass)) {
            if ($this->supportsLazyObjects && isset($this->lazyFactories[$paramTypeName])) {
                return $this->newLazyInstance($paramTypeName);
            }

            return $bindClass($this->container);
        }

        if (is_object($bindClass)) {
            return $bindClass;
        }

        $this->checkCircularDependency($paramTypeName);

        $plan = $this->describeClass($paramTypeName);
        if (!$plan['instantiable']) {
            $paramTypeName = $this->resolveConcreteForAbstract($paramTypeName);
            $plan = $this->describeClass($paramTypeName);
        }

        // Deferred here rather than in instantiateFromPlan(): a lazy target must
        // not have its constructor arguments resolved yet, and that is what the
        // plan-driven path exists to do.
        //
        // The field guards the call rather than isLazy() alone: on PHP 8.3
        // nothing is ever lazy, and this runs for every node of every graph.
        if ($this->supportsLazyObjects && $this->isLazy($paramTypeName)) {
            return $this->newLazyInstance($paramTypeName);
        }

        return $this->instantiateFromPlan($paramTypeName, $plan);
    }

    /**
     * @param class-string $abstract
     *
     * @return class-string
     */
    private function resolveConcreteForAbstract(string $abstract): string
    {
        $concrete = $this->bindings[$abstract] ?? null;
        if (is_string($concrete)) {
            /** @var class-string $concrete */
            return $concrete;
        }

        $knownBindings = $this->parent === null
            ? $this->bindings
            : $this->bindings + $this->parent->getBindings();

        $suggestions = FuzzyMatcher::findSimilar($abstract, array_keys($knownBindings));

        throw DependencyNotFoundException::mapNotFoundForClassName($abstract, $suggestions);
    }

    /**
     * A real instance of $className whose constructor runs on first use.
     *
     * Dependencies are resolved inside the initializer, not before it, so a
     * lazy service that is never touched costs nothing to build.
     *
     * @param class-string $className
     */
    private function newLazyGhost(string $className): object
    {
        $reflection = new ReflectionClass($className);

        /**
         * newLazyGhost() is PHP 8.4+, and the analysers target the 8.3 floor,
         * so they cannot see it. Callers gate this behind a runtime capability
         * check, which is exactly what they cannot model.
         *
         * Calling __construct() directly on the ghost is the documented way to
         * initialize one, not an accident.
         *
         * @psalm-suppress UndefinedMethod, MixedReturnStatement, MixedInferredReturnType
         *
         * @phpstan-ignore method.notFound, return.type
         */
        return $reflection->newLazyGhost(function (object $instance) use ($className): void {
            $dependencies = $this->resolveDependencies($className);

            /**
             * @psalm-suppress MixedMethodCall, DirectConstructorCall
             *
             * @phpstan-ignore method.notFound
             */
            $instance->__construct(...$dependencies);

            // Deferred with the constructor, not run eagerly at ghost creation:
            // resolving them up front would defeat the point of being lazy.
            if ($this->hasInjectedProperties($className)) {
                $this->injectPropertiesOn($instance, $className);
            }
        });
    }

    /**
     * A real instance of $className produced by $factory on first use.
     *
     * @param class-string $className
     */
    private function newLazyProxy(string $className, Closure $factory): object
    {
        $reflection = new ReflectionClass($className);

        /**
         * newLazyProxy() is PHP 8.4+; see newLazyGhost() for why the analysers
         * cannot see it.
         *
         * @psalm-suppress UndefinedMethod, MixedReturnStatement, MixedInferredReturnType
         *
         * @phpstan-ignore method.notFound, return.type
         */
        return $reflection->newLazyProxy(function () use ($factory): object {
            /** @var object */
            return $factory($this->container);
        });
    }

    /**
     * @param class-string $className
     * @param ClassPlan $plan
     */
    private function instantiateFromPlan(string $className, array $plan): object
    {
        $this->resolvingStack[$className] = true;

        try {
            /** @var list<mixed> $args */
            $args = [];

            foreach ($plan['params'] as $param) {
                // Nested constructors skip untyped parameters, relying on their defaults.
                if (!$param['hasType']) {
                    continue;
                }

                /** @psalm-suppress MixedAssignment */
                $args[] = $this->resolveParameter($param);
            }

            /** @psalm-suppress MixedMethodCall */
            $instance = new $className(...$args);

            // Read off the plan already in hand, and skipped entirely when
            // there is nothing to inject — this runs for every node of every
            // object graph.
            if ($plan['props'] !== []) {
                // Still inside the try, so the class remains on the resolving
                // stack: a cycle reached through a property is caught like any
                // other.
                $this->injectProperties($instance, $plan['props']);
            }

            return $instance;
        } finally {
            unset($this->resolvingStack[$className]);
        }
    }

    /**
     * @param class-string $className
     *
     * @return ClassPlan
     */
    private function describeClass(string $className): array
    {
        $plan = $this->planRegistry->plans[$className] ?? null;

        if ($plan === null) {
            $reflection = new ReflectionClass($className);
            $constructor = $reflection->getConstructor();

            $params = [];
            if ($constructor !== null) {
                foreach ($constructor->getParameters() as $parameter) {
                    $params[] = $this->describeParameter($parameter);
                }
            }

            return $this->planRegistry->plans[$className] = [
                'instantiable' => $reflection->isInstantiable(),
                'params' => $params,
                'props' => $this->describeProperties($className),
            ];
        }

        // A cache written before property injection existed has no 'props'.
        // Filling it in beats trusting it: a stale file would otherwise
        // silently turn injection off in exactly the environment (production)
        // where the cache is used.
        if (!isset($plan['props'])) {
            return $this->planRegistry->plans[$className] = [
                'instantiable' => $plan['instantiable'],
                'params' => $plan['params'],
                'props' => $this->describeProperties($className),
            ];
        }

        return $plan;
    }

    /**
     * The #[Inject] properties of $className, including inherited private ones.
     *
     * @param class-string $className
     *
     * @return list<PropPlan>
     */
    private function describeProperties(string $className): array
    {
        return self::$propertyPlans[$className] ??= $this->scanProperties($className);
    }

    /**
     * @param class-string $className
     *
     * @return list<PropPlan>
     */
    private function scanProperties(string $className): array
    {
        $reflection = new ReflectionClass($className);

        // The leaf lists its own properties plus inherited public and protected
        // ones. Private properties declared upstream are the only gap, and each
        // ancestor reports exactly its own, so the walk cannot double up — a
        // private property shadowing a parent's stays a separate entry.
        $properties = $reflection->getProperties();

        for ($parent = $reflection->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            foreach ($parent->getProperties(ReflectionProperty::IS_PRIVATE) as $property) {
                $properties[] = $property;
            }
        }

        $props = [];

        foreach ($properties as $property) {
            // A promoted property carries the parameter's attribute, and the
            // constructor already acted on it.
            if ($property->isStatic() || $property->isPromoted()) {
                continue;
            }

            $plan = $this->describeProperty($property);
            if ($plan !== null) {
                $props[] = $plan;
            }
        }

        return $props;
    }

    /**
     * @return PropPlan|null null when the property carries no #[Inject]
     */
    private function describeProperty(ReflectionProperty $property): ?array
    {
        $attributes = $property->getAttributes(Inject::class);
        if ($attributes === []) {
            return null;
        }

        /** @var Inject $inject */
        $inject = $attributes[0]->newInstance();

        $type = $property->getType();
        $typeName = null;
        $isScalar = false;
        if ($type instanceof ReflectionNamedType) {
            $typeName = $type->getName();
            $isScalar = $this->isScalar($typeName);
        }

        /** @var class-string|null $implementation */
        $implementation = $inject->implementation;

        return [
            'name' => $property->getName(),
            'hasType' => $type instanceof ReflectionNamedType,
            'type' => $typeName,
            'isScalar' => $isScalar,
            'inject' => $implementation,
            'isReadonly' => $property->isReadOnly(),
            'declaringClass' => $property->getDeclaringClass()->getName(),
        ];
    }

    /**
     * @param list<PropPlan> $props
     */
    private function injectProperties(object $instance, array $props): void
    {
        foreach ($props as $prop) {
            if ($prop['isReadonly']) {
                throw DependencyInvalidArgumentException::readonlyPropertyInjection(
                    $prop['declaringClass'],
                    $prop['name'],
                    $this->getResolutionChain(),
                );
            }

            $this->handleFor($prop)->setValue($instance, $this->resolveProperty($prop));
        }
    }

    /**
     * @param PropPlan $prop
     */
    private function resolveProperty(array $prop): mixed
    {
        $declaringClass = $prop['declaringClass'];

        if ($prop['inject'] !== null) {
            return $this->resolveClass($prop['inject']);
        }

        if (!$prop['hasType']) {
            throw DependencyInvalidArgumentException::noPropertyTypeFor(
                $declaringClass,
                $prop['name'],
                $this->getResolutionChain(),
            );
        }

        /** @var string $type hasType was just checked, so this is never null */
        $type = $prop['type'];

        if ($prop['isScalar']) {
            throw DependencyInvalidArgumentException::unableToResolveProperty(
                $declaringClass,
                $prop['name'],
                $type,
                $this->getResolutionChain(),
            );
        }

        /** @var class-string $type */
        return $this->resolveClass($type);
    }

    /**
     * @param PropPlan $prop
     */
    private function handleFor(array $prop): ReflectionProperty
    {
        return $this->propertyHandles[$prop['declaringClass'] . '::' . $prop['name']]
            ??= new ReflectionProperty($prop['declaringClass'], $prop['name']);
    }

    /**
     * The parameter plan of a callable, memoized.
     *
     * Every other reflection result here is memoized and this one was not, so a
     * repeated resolve() re-built a ReflectionFunction and re-described every
     * parameter each time — costing more than the resolution it was feeding.
     *
     * @return list<ParamPlan>
     */
    private function describeCallable(callable $callable): array
    {
        $signature = CallableKey::signatureFor($callable);

        if ($signature !== null) {
            // Closure::fromCallable() allocates a closure for every form but a
            // Closure, so it is built here on a miss rather than by the caller
            // on every call: with the plan cached there is nothing to reflect
            // and nothing to convert.
            return $this->callablePlans[$signature] ??= $this->describeFunction(Closure::fromCallable($callable));
        }

        // A closure has no name to key on, so the object is its own identity —
        // weakly, or a container would pin every closure ever passed to
        // resolve() for as long as it lived.
        /** @var Closure $callable a null signature means exactly this */
        $plans = $this->closurePlans;

        if ($plans === null) {
            /** @var WeakMap<Closure, list<ParamPlan>> $plans */
            $plans = new WeakMap();
            $this->closurePlans = $plans;
        }

        $plan = $plans[$callable] ?? null;

        if ($plan === null) {
            $plan = $this->describeFunction($callable);
            $plans[$callable] = $plan;
        }

        return $plan;
    }

    /**
     * @return list<ParamPlan>
     */
    private function describeFunction(Closure $closure): array
    {
        $params = [];
        foreach ((new ReflectionFunction($closure))->getParameters() as $parameter) {
            $params[] = $this->describeParameter($parameter);
        }

        return $params;
    }

    /**
     * @return ParamPlan
     */
    private function describeParameter(ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();

        $typeName = null;
        $isScalar = false;
        if ($type instanceof ReflectionNamedType) {
            $typeName = $type->getName();
            $isScalar = $this->isScalar($typeName);
        }

        $hasDefault = $parameter->isDefaultValueAvailable();

        return [
            'name' => $parameter->getName(),
            'hasType' => $parameter->hasType(),
            'type' => $typeName,
            'isScalar' => $isScalar,
            'inject' => $this->readInjectImplementation($parameter),
            'hasDefault' => $hasDefault,
            'default' => $hasDefault ? $parameter->getDefaultValue() : null,
            'declaringClass' => $parameter->getDeclaringClass()?->getName(),
        ];
    }

    /**
     * @return class-string|null
     */
    private function readInjectImplementation(ReflectionParameter $parameter): ?string
    {
        $attributes = $parameter->getAttributes(Inject::class);
        if (count($attributes) === 0) {
            return null;
        }

        /** @var Inject $inject */
        $inject = $attributes[0]->newInstance();

        /** @var class-string|null $implementation */
        $implementation = $inject->implementation;

        return $implementation;
    }

    /**
     * @return list<string>
     */
    private function getResolutionChain(): array
    {
        return array_keys($this->resolvingStack);
    }

    private function isScalar(string $paramTypeName): bool
    {
        $this->typeExistsCache[$paramTypeName] ??= class_exists($paramTypeName)
            || interface_exists($paramTypeName);

        return !$this->typeExistsCache[$paramTypeName];
    }

    /**
     * @param class-string $className
     */
    private function checkCircularDependency(string $className): void
    {
        if (isset($this->resolvingStack[$className])) {
            $chain = array_keys($this->resolvingStack);
            $chain[] = $className;
            throw CircularDependencyException::create($chain);
        }
    }

    /**
     * @param class-string $abstract
     */
    private function getContextualBinding(string $abstract): mixed
    {
        if ($this->contextualBindings === []) {
            return null;
        }

        // Walk the build stack from the end (most specific context) outward
        for ($i = count($this->buildStack) - 1; $i >= 0; --$i) {
            $concrete = $this->buildStack[$i];
            if (isset($this->contextualBindings[$concrete][$abstract])) {
                return $this->contextualBindings[$concrete][$abstract];
            }
        }

        return null;
    }
}
