<?php

declare(strict_types=1);

namespace Gacela\Resolver;

final class InstanceCreator
{
    private ?DependencyResolver $dependencyResolver = null;

    /** @var array<class-string,list<mixed>> */
    private array $cachedDependencies = [];

    /**
     * @param array<class-string,class-string|callable|object> $mappingInterfaces
     */
    public function __construct(
        private array $mappingInterfaces = [],
    ) {
    }

    /**
     * @param class-string $className
     */
    public static function create(string $className): ?object
    {
        return (new self())->createByClassName($className);
    }

    /**
     * @param class-string $className
     */
    public function createByClassName(string $className): ?object
    {
        if (class_exists($className)) {
            if (!isset($this->cachedDependencies[$className])) {
                $this->cachedDependencies[$className] = $this
                    ->getDependencyResolver()
                    ->resolveDependencies($className);
            }

            /** @psalm-suppress MixedMethodCall */
            return new $className(...$this->cachedDependencies[$className]);
        }

        return null;
    }

    private function getDependencyResolver(): DependencyResolver
    {
        if ($this->dependencyResolver === null) {
            $this->dependencyResolver = new DependencyResolver(
                $this->mappingInterfaces,
            );
        }

        return $this->dependencyResolver;
    }
}
