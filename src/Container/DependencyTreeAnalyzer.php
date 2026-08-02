<?php

declare(strict_types=1);

namespace Gacela\Container;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

use function class_exists;

/**
 * Analyzes and reports dependency trees for classes.
 *
 * @internal
 * Not covered by backward compatibility: this class is an implementation
 * detail of Container and may change or disappear in any release
 */
final readonly class DependencyTreeAnalyzer
{
    public function __construct(
        private BindingResolver $bindingResolver,
    ) {
    }

    /**
     * Get all dependencies for a class as a flat list.
     *
     * Derived from the graph rather than walked separately: two traversals over
     * the same constructors can only stay in agreement if there is one of them.
     *
     * @param class-string $className
     *
     * @return list<class-string>
     */
    public function analyze(string $className): array
    {
        if (!class_exists($className)) {
            return [];
        }

        return $this->graph($className)->flatten();
    }

    /**
     * The dependency graph rooted at $className, keeping the depth, the parent
     * and the parameter name that a flat list throws away.
     *
     * A class that cannot be loaded or has no constructor yields a childless
     * root rather than an error: this is a debugging call, and refusing to
     * describe a broken graph is the opposite of useful.
     *
     * @param class-string $className
     */
    public function graph(string $className): DependencyNode
    {
        $memo = [];
        $cut = false;

        return $this->buildNode($className, null, [], $memo, $cut);
    }

    /**
     * @param class-string $className
     * @param array<class-string, true> $ancestors the classes open on this path
     * @param array<class-string, list<DependencyNode>> $memo children of classes
     *   whose subtree turned out not to depend on the path taken to reach them
     * @param bool $cut set when this node or anything below it closed a cycle
     */
    private function buildNode(
        string $className,
        ?string $parameter,
        array $ancestors,
        array &$memo,
        bool &$cut,
    ): DependencyNode {
        // Cut on an ancestor rather than on anything seen anywhere: a class
        // three parents ask for genuinely appears three times, and hiding that
        // is exactly what the flat list already does.
        if (isset($ancestors[$className])) {
            $cut = true;

            return new DependencyNode($className, $parameter, [], true);
        }

        // A subtree that closed no cycle is the same subtree wherever it hangs,
        // and the nodes are readonly, so every parent asking for this class can
        // share one copy of its children. Without this, a graph where classes
        // depend on several others grows with the number of distinct *paths*
        // rather than classes — 25 classes measured 318k nodes and 150ms, and
        // getDependencyTree() is derived from this.
        if (isset($memo[$className])) {
            return new DependencyNode($className, $parameter, $memo[$className]);
        }

        $constructor = self::constructorOf($className);

        // A leaf either way, and a class that cannot be loaded is described as
        // one rather than raising: this is a debugging call, and refusing to
        // describe a broken graph is the opposite of useful.
        if ($constructor === null) {
            $memo[$className] = [];

            return new DependencyNode($className, $parameter);
        }

        $ancestors[$className] = true;
        $children = [];
        $cutBelow = false;

        foreach ($constructor->getParameters() as $reflectionParameter) {
            $type = $reflectionParameter->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            /** @var class-string $paramTypeName */
            $paramTypeName = $type->getName();

            $paramTypeName = $this->bindingResolver->resolveType($paramTypeName);

            $childCut = false;
            $children[] = $this->buildNode($paramTypeName, $reflectionParameter->getName(), $ancestors, $memo, $childCut);
            $cutBelow = $cutBelow || $childCut;
        }

        // Only cycle-free subtrees are reusable: one that was cut was cut
        // against *this* path's ancestors, and would be wrong under a parent
        // reached another way.
        if (!$cutBelow) {
            $memo[$className] = $children;
        }

        $cut = $cut || $cutBelow;

        return new DependencyNode($className, $parameter, $children);
    }

    /**
     * Kept apart so the class_exists() check does not widen $className back to
     * a plain string for the caller, which needs it to stay a class-string.
     *
     * @param class-string $className
     */
    private static function constructorOf(string $className): ?ReflectionMethod
    {
        if (!class_exists($className)) {
            return null;
        }

        return (new ReflectionClass($className))->getConstructor();
    }
}
