<?php

declare(strict_types=1);

namespace Gacela\Container;

use Closure;
use Gacela\Container\Attribute\Inject;
use Gacela\Container\Exception\CircularDependencyException;
use Gacela\Container\Exception\DependencyInvalidArgumentException;
use Gacela\Container\Exception\DependencyNotFoundException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;

use function array_key_exists;
use function array_keys;
use function class_exists;
use function count;
use function is_callable;
use function is_object;
use function is_string;

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

    /** @var array<string, ReflectionProperty> */
    private array $propertyHandles = [];

    private ?Container $parent = null;

    /**
     * Hoisted out of $parent so the fall-through test costs a bool read on the
     * hot path. Every unbound constructor parameter of every graph reaches it,
     * and for a container with no parent — nearly all of them — the answer is
     * fixed for the resolver's whole life.
     */
    private bool $hasParent = false;

    /**
     * @param BindingsMap $bindings
     * @param ContextualBindingsMap $contextualBindings
     */
    public function __construct(
        private array &$bindings = [],
        private array &$contextualBindings = [],
        private PlanRegistry $planRegistry = new PlanRegistry(),
        private ?ContainerInterface $container = null,
    ) {
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
     * @param class-string|Closure $toResolve
     * @param array<string, mixed> $overrides runtime values keyed by parameter name (top level only)
     *
     * @return list<mixed>
     */
    public function resolveDependencies(string|Closure $toResolve, array $overrides = []): array
    {
        if (!is_string($toResolve)) {
            return $this->resolveEntryParameters($this->describeFunction($toResolve), $overrides);
        }

        // Track which class is being resolved for contextual bindings.
        $this->buildStack[] = $toResolve;

        try {
            return $this->resolveEntryParameters($this->describeClass($toResolve)['params'], $overrides);
        } finally {
            array_pop($this->buildStack);
        }
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
            if (is_callable($contextualBinding)) {
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

        if (is_callable($bindClass)) {
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
