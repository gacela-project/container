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
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

use function count;
use function is_callable;
use function is_object;
use function is_string;

/**
 * @psalm-import-type Binding from ContainerInterface
 * @psalm-import-type BindingsMap from ContainerInterface
 * @psalm-import-type ContextualBindingsMap from ContainerInterface
 */
final class DependencyResolver
{
    /** @var array<class-string, ReflectionClass<object>> */
    private array $reflectionCache = [];

    /** @var array<class-string, ?ReflectionMethod> */
    private array $constructorCache = [];

    /** @var array<class-string, bool> */
    private array $resolvingStack = [];

    /** @var array<string, bool> Memoized class_exists()/interface_exists() checks */
    private array $typeExistsCache = [];

    /** @var list<class-string> */
    private array $buildStack = [];

    /**
     * @param BindingsMap $bindings
     * @param ContextualBindingsMap $contextualBindings
     */
    public function __construct(
        private array $bindings = [],
        private array &$contextualBindings = [],
    ) {
    }

    /**
     * @param class-string|Closure $toResolve
     *
     * @return list<mixed>
     */
    public function resolveDependencies(string|Closure $toResolve): array
    {
        // Track which class is being resolved for contextual bindings
        if (is_string($toResolve)) {
            $this->buildStack[] = $toResolve;
        }

        try {
            /** @var list<mixed> $dependencies */
            $dependencies = [];

            $parameters = $this->getParametersToResolve($toResolve);

            foreach ($parameters as $parameter) {
                /** @psalm-suppress MixedAssignment */
                $dependencies[] = $this->resolveDependenciesRecursively($parameter);
            }

            return $dependencies;
        } finally {
            if (is_string($toResolve)) {
                array_pop($this->buildStack);
            }
        }
    }

    /**
     * @param class-string|Closure $toResolve
     *
     * @return list<ReflectionParameter>
     */
    private function getParametersToResolve(Closure|string $toResolve): array
    {
        if (is_string($toResolve)) {
            $constructor = $this->getConstructor($toResolve);
            if (!$constructor) {
                return [];
            }
            return $constructor->getParameters();
        }

        $reflection = new ReflectionFunction($toResolve);
        return $reflection->getParameters();
    }

    /**
     * @param class-string $className
     */
    private function getConstructor(string $className): ?ReflectionMethod
    {
        if (!isset($this->constructorCache[$className])) {
            $reflection = new ReflectionClass($className);
            $this->constructorCache[$className] = $reflection->getConstructor();
        }

        return $this->constructorCache[$className];
    }

    private function resolveDependenciesRecursively(ReflectionParameter $parameter): mixed
    {
        $this->checkInvalidArgumentParam($parameter);

        $attributes = $parameter->getAttributes(Inject::class);
        if (count($attributes) > 0) {
            /** @var Inject $inject */
            $inject = $attributes[0]->newInstance();
            if ($inject->implementation !== null) {
                return $this->resolveClass($inject->implementation);
            }
        }

        /** @var ReflectionNamedType $paramType */
        $paramType = $parameter->getType();

        /** @var class-string $paramTypeName */
        $paramTypeName = $paramType->getName();
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        return $this->resolveClass($paramTypeName);
    }

    private function checkInvalidArgumentParam(ReflectionParameter $parameter): void
    {
        if (!$parameter->hasType()) {
            $chain = $this->getResolutionChain();
            throw DependencyInvalidArgumentException::noParameterTypeFor($parameter->getName(), $chain);
        }

        /** @var ReflectionNamedType $paramType */
        $paramType = $parameter->getType();
        $paramTypeName = $paramType->getName();

        if ($this->isScalar($paramTypeName) && !$parameter->isDefaultValueAvailable()) {
            /** @var ReflectionClass<object> $reflectionClass */
            $reflectionClass = $parameter->getDeclaringClass();
            $chain = $this->getResolutionChain();
            throw DependencyInvalidArgumentException::unableToResolve($paramTypeName, $reflectionClass->getName(), $chain);
        }
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

        $reflection = $this->resolveReflectionClass($paramTypeName);
        // Use the concrete class name for caching, not the original parameter type
        $constructor = $this->getConstructor($reflection->getName());
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        return $this->resolveInnerDependencies($constructor, $reflection);
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
     * @param class-string $paramTypeName
     *
     * @return ReflectionClass<object>
     */
    private function resolveReflectionClass(string $paramTypeName): ReflectionClass
    {
        if (!isset($this->reflectionCache[$paramTypeName])) {
            $reflection = new ReflectionClass($paramTypeName);

            if (!$reflection->isInstantiable()) {
                $concreteClass = $this->bindings[$reflection->getName()] ?? null;

                if ($concreteClass !== null) {
                    /** @var class-string $concreteClass */
                    $reflection = new ReflectionClass($concreteClass);
                } else {
                    $suggestions = FuzzyMatcher::findSimilar(
                        $reflection->getName(),
                        array_keys($this->bindings),
                    );
                    throw DependencyNotFoundException::mapNotFoundForClassName(
                        $reflection->getName(),
                        $suggestions,
                    );
                }
            }

            $this->reflectionCache[$paramTypeName] = $reflection;
        }

        return $this->reflectionCache[$paramTypeName];
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function resolveInnerDependencies(ReflectionMethod $constructor, ReflectionClass $reflection): object
    {
        $className = $reflection->getName();
        $this->resolvingStack[$className] = true;

        try {
            /** @var list<mixed> $innerDependencies */
            $innerDependencies = [];

            foreach ($constructor->getParameters() as $constructorParameter) {
                $paramType = $constructorParameter->getType();
                if ($paramType) {
                    /** @psalm-suppress MixedAssignment */
                    $innerDependencies[] = $this->resolveDependenciesRecursively($constructorParameter);
                }
            }

            return $reflection->newInstanceArgs($innerDependencies);
        } finally {
            unset($this->resolvingStack[$className]);
        }
    }

    /**
     * @param class-string $abstract
     *
     * @return Binding|null
     */
    private function getContextualBinding(string $abstract): mixed
    {
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
