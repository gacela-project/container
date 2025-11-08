<?php

declare(strict_types=1);

namespace Gacela\Container;

use ReflectionClass;
use ReflectionNamedType;

use function class_exists;

/**
 * Analyzes and reports dependency trees for classes.
 */
final class DependencyTreeAnalyzer
{
    public function __construct(
        private BindingResolver $bindingResolver,
    ) {
    }

    /**
     * Get all dependencies for a class as a flat list.
     *
     * @param class-string $className
     *
     * @return list<string>
     */
    public function analyze(string $className): array
    {
        if (!class_exists($className)) {
            return [];
        }

        $dependencies = [];
        $this->collectDependencies($className, $dependencies);

        /** @var list<string> */
        return array_keys($dependencies);
    }

    /**
     * Recursively collect dependencies for a class.
     *
     * @param class-string $className
     * @param array<string, true> $dependencies
     */
    private function collectDependencies(string $className, array &$dependencies): void
    {
        $reflection = new ReflectionClass($className);

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return;
        }

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            /** @var class-string $paramTypeName */
            $paramTypeName = $type->getName();

            // Resolve binding if it's an interface
            $paramTypeName = $this->bindingResolver->resolveType($paramTypeName);

            if (isset($dependencies[$paramTypeName])) {
                continue; // Already processed
            }

            $dependencies[$paramTypeName] = true;

            if (class_exists($paramTypeName)) {
                $this->collectDependencies($paramTypeName, $dependencies);
            }
        }
    }
}
