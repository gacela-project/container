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

use function array_key_exists;
use function array_keys;
use function count;
use function is_callable;
use function is_object;
use function is_string;

/**
 * @psalm-import-type Binding from ContainerInterface
 * @psalm-import-type BindingsMap from ContainerInterface
 * @psalm-import-type ContextualBindingsMap from ContainerInterface
 *
 * @psalm-type ParamPlan = array{name: string, hasType: bool, type: string|null, isScalar: bool, inject: class-string|null, hasDefault: bool, default: mixed, declaringClass: string|null}
 * @psalm-type ClassPlan = array{instantiable: bool, params: list<ParamPlan>}
 * @psalm-type CompiledPlans = array<class-string, ClassPlan>
 */
final class DependencyResolver
{
    /**
     * Plain-data constructor plans per class. Seeded from a compiled cache to
     * skip reflection at runtime, and populated lazily otherwise.
     *
     * @var CompiledPlans
     */
    private array $planCache;

    /** @var array<class-string, bool> */
    private array $resolvingStack = [];

    /** @var array<string, bool> Memoized class_exists()/interface_exists() checks */
    private array $typeExistsCache = [];

    /** @var list<class-string> */
    private array $buildStack = [];

    /**
     * @param BindingsMap $bindings
     * @param ContextualBindingsMap $contextualBindings
     * @param CompiledPlans $compiledPlans
     */
    public function __construct(
        private array $bindings = [],
        private array &$contextualBindings = [],
        array $compiledPlans = [],
    ) {
        $this->planCache = $compiledPlans;
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
     * The compiled constructor plans gathered so far, for persisting to a cache.
     *
     * @return CompiledPlans
     */
    public function exportPlans(): array
    {
        return $this->planCache;
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
     * @param class-string $paramTypeName
     */
    private function resolveClass(string $paramTypeName): mixed
    {
        $contextualBinding = $this->getContextualBinding($paramTypeName);
        if ($contextualBinding !== null) {
            if (is_callable($contextualBinding)) {
                /** @psalm-suppress MixedFunctionCall */
                return $contextualBinding();
            }

            if (is_object($contextualBinding)) {
                return $contextualBinding;
            }

            // It's a class string - use it instead of the interface
            /** @var class-string $contextualBinding */
            $paramTypeName = $contextualBinding;
        }

        $bindClass = $this->bindings[$paramTypeName] ?? null;
        if (is_callable($bindClass)) {
            return $bindClass();
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

        return $this->instantiateFromPlan($paramTypeName, $plan['params']);
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

        $suggestions = FuzzyMatcher::findSimilar($abstract, array_keys($this->bindings));

        throw DependencyNotFoundException::mapNotFoundForClassName($abstract, $suggestions);
    }

    /**
     * @param class-string $className
     * @param list<ParamPlan> $params
     */
    private function instantiateFromPlan(string $className, array $params): object
    {
        $this->resolvingStack[$className] = true;

        try {
            /** @var list<mixed> $args */
            $args = [];

            foreach ($params as $param) {
                // Nested constructors skip untyped parameters, relying on their defaults.
                if (!$param['hasType']) {
                    continue;
                }

                /** @psalm-suppress MixedAssignment */
                $args[] = $this->resolveParameter($param);
            }

            /** @psalm-suppress MixedMethodCall */
            return new $className(...$args);
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
        if (!isset($this->planCache[$className])) {
            $reflection = new ReflectionClass($className);
            $constructor = $reflection->getConstructor();

            $params = [];
            if ($constructor !== null) {
                foreach ($constructor->getParameters() as $parameter) {
                    $params[] = $this->describeParameter($parameter);
                }
            }

            $this->planCache[$className] = [
                'instantiable' => $reflection->isInstantiable(),
                'params' => $params,
            ];
        }

        return $this->planCache[$className];
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
     *
     * @return Binding|null
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
