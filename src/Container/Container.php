<?php

declare(strict_types=1);

namespace Gacela\Container;

use Psr\Container\ContainerInterface;

use function is_callable;
use function is_object;

final class Container implements ContainerInterface
{
    private ?DependencyResolver $dependencyResolver = null;

    /** @var array<class-string,list<mixed>> */
    private array $cachedDependencies = [];

    /**
     * @param array<class-string, class-string|callable|object> $bindings
     */
    public function __construct(
        private array $bindings = [],
    ) {
    }

    /**
     * @param class-string $className
     */
    public static function create(string $className): ?object
    {
        return (new self())->get($className);
    }

    public function has(string $id): bool
    {
        return is_object($this->get($id));
    }

    /**
     * @param class-string|string $id
     */
    public function get(string $id): ?object
    {
        if (isset($this->bindings[$id])) {
            $binding = $this->bindings[$id];
            if (is_callable($binding)) {
                /** @var mixed $binding */
                $binding = $binding();
            }
            if (is_object($binding)) {
                return $binding;
            }

            /** @var class-string $binding */
            if (class_exists($binding)) {
                return $this->instantiateClass($binding);
            }
        }

        if (class_exists($id)) {
            return $this->instantiateClass($id);
        }

        return null;
    }

    /**
     * @param class-string $class
     */
    private function instantiateClass(string $class): ?object
    {
        if (class_exists($class)) {
            if (!isset($this->cachedDependencies[$class])) {
                $this->cachedDependencies[$class] = $this
                    ->getDependencyResolver()
                    ->resolveDependencies($class);
            }

            /** @psalm-suppress MixedMethodCall */
            return new $class(...$this->cachedDependencies[$class]);
        }

        return null;
    }

    private function getDependencyResolver(): DependencyResolver
    {
        if ($this->dependencyResolver === null) {
            $this->dependencyResolver = new DependencyResolver(
                $this->bindings,
            );
        }

        return $this->dependencyResolver;
    }
}
