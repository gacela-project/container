<?php

declare(strict_types=1);

namespace Gacela\Container;

use Gacela\Container\Exception\ContainerException;

use function is_object;
use function method_exists;

/**
 * Manages service instance storage and frozen state.
 */
final class InstanceRegistry
{
    /** @var array<string,mixed> */
    private array $instances = [];

    /** @var array<string,bool> */
    private array $frozenInstances = [];

    public function has(string $id): bool
    {
        return isset($this->instances[$id]);
    }

    /**
     * @throws ContainerException if instance is frozen
     */
    public function set(string $id, mixed $instance): void
    {
        if (isset($this->frozenInstances[$id])) {
            throw ContainerException::frozenInstanceOverride($id);
        }

        $this->instances[$id] = $instance;
    }

    /**
     * Resolve and return the instance; freezes it as a side effect.
     * Factory closures are re-invoked each call; protected closures are returned as-is.
     */
    public function get(string $id, FactoryManager $factoryManager, ContainerInterface $container): mixed
    {
        $this->frozenInstances[$id] = true;

        /** @var mixed $instance */
        $instance = $this->instances[$id];

        if (!is_object($instance)
            || $factoryManager->isProtected($instance)
            || !method_exists($instance, '__invoke')
        ) {
            return $instance;
        }

        if ($factoryManager->isFactory($instance)) {
            return $instance($container);
        }

        /** @var mixed $resolvedService */
        $resolvedService = $instance($container);

        $this->instances[$id] = $resolvedService;

        return $resolvedService;
    }

    /**
     * Remove a service instance and its frozen state.
     */
    public function remove(string $id): void
    {
        unset(
            $this->instances[$id],
            $this->frozenInstances[$id],
        );
    }

    public function isFrozen(string $id): bool
    {
        return isset($this->frozenInstances[$id]);
    }

    /**
     * @return list<string>
     */
    public function getAll(): array
    {
        return array_keys($this->instances);
    }

    /**
     * Get the stored value without invoking closures or freezing the service.
     */
    public function getRaw(string $id): mixed
    {
        return $this->instances[$id] ?? null;
    }
}
