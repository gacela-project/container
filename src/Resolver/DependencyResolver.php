<?php

declare(strict_types=1);

namespace Gacela\Resolver;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

use function is_callable;
use function is_object;

final class DependencyResolver
{
    /**
     * @param array<class-string,class-string|callable|object> $bindings
     */
    public function __construct(
        private array $bindings = [],
    ) {
    }

    /**
     * @param class-string $resolvedClassName
     *
     * @return list<mixed>
     */
    public function resolveDependencies(string $resolvedClassName): array
    {
        $reflection = new ReflectionClass($resolvedClassName);
        $constructor = $reflection->getConstructor();
        if (!$constructor) {
            return [];
        }

        /** @var list<mixed> $dependencies */
        $dependencies = [];
        foreach ($constructor->getParameters() as $parameter) {
            /** @psalm-suppress MixedAssignment */
            $dependencies[] = $this->resolveDependenciesRecursively($parameter);
        }

        return $dependencies;
    }

    private function resolveDependenciesRecursively(ReflectionParameter $parameter): mixed
    {
        $this->checkInvalidArgumentParam($parameter);

        /** @var ReflectionNamedType $paramType */
        $paramType = $parameter->getType();

        /** @var class-string $paramTypeName */
        $paramTypeName = $paramType->getName();
        if ($this->isScalar($paramTypeName) && $parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        return $this->resolveClass($paramTypeName);
    }

    private function checkInvalidArgumentParam(ReflectionParameter $parameter): void
    {
        if (!$parameter->hasType()) {
            throw DependencyInvalidArgumentException::noParameterTypeFor($parameter->getName());
        }

        /** @var ReflectionNamedType $paramType */
        $paramType = $parameter->getType();
        $paramTypeName = $paramType->getName();

        if ($this->isScalar($paramTypeName) && !$parameter->isDefaultValueAvailable()) {
            /** @var ReflectionClass $reflectionClass */
            $reflectionClass = $parameter->getDeclaringClass();
            throw DependencyInvalidArgumentException::unableToResolve($paramTypeName, $reflectionClass->getName());
        }
    }

    private function isScalar(string $paramTypeName): bool
    {
        return !class_exists($paramTypeName)
            && !interface_exists($paramTypeName);
    }

    /**
     * @param class-string $paramTypeName
     */
    private function resolveClass(string $paramTypeName): mixed
    {
        /** @var mixed $bindClass */
        $bindClass = $this->bindings[$paramTypeName] ?? null;
        if (is_callable($bindClass)) {
            return $bindClass();
        }

        if (is_object($bindClass)) {
            return $bindClass;
        }

        $reflection = $this->resolveReflectionClass($paramTypeName);
        $constructor = $reflection->getConstructor();
        if (!$constructor) {
            return $reflection->newInstance();
        }

        return $this->resolveInnerDependencies($constructor, $reflection);
    }

    /**
     * @param class-string $paramTypeName
     */
    private function resolveReflectionClass(string $paramTypeName): ReflectionClass
    {
        $reflection = new ReflectionClass($paramTypeName);

        if ($reflection->isInstantiable()) {
            return $reflection;
        }

        /** @var mixed $concreteClass */
        $concreteClass = $this->bindings[$reflection->getName()] ?? null;

        if ($concreteClass !== null) {
            /** @var class-string $concreteClass */
            return new ReflectionClass($concreteClass);
        }

        throw DependencyNotFoundException::mapNotFoundForClassName($reflection->getName());
    }

    private function resolveInnerDependencies(ReflectionMethod $constructor, ReflectionClass $reflection): object
    {
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
    }
}
