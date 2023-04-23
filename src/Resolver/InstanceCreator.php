<?php

declare(strict_types=1);

namespace Gacela\Resolver;

use Psr\Container\ContainerInterface;

use function is_object;

final class InstanceCreator implements ContainerInterface
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
     * @param class-string $className
     *
     * @deprecated Use method 'get(string $id)' instead
     */
    public function createByClassName(string $className): ?object
    {
        return $this->get($className);
    }

    /**
     * @param class-string|string $id
     */
    public function get(string $id): ?object
    {
        if (class_exists($id)) {
            if (!isset($this->cachedDependencies[$id])) {
                $this->cachedDependencies[$id] = $this
                    ->getDependencyResolver()
                    ->resolveDependencies($id);
            }

            /** @psalm-suppress MixedMethodCall */
            return new $id(...$this->cachedDependencies[$id]);
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
