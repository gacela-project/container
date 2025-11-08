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

    /**
     * Check if an instance is registered.
     */
    public function has(string $id): bool
    {
        return isset($this->instances[$id]);
    }

    /**
     * Store a service instance.
     *
     * @throws ContainerException if instance is frozen
     */
    public function set(string $id, mixed $instance): void
    {
        if (!empty($this->frozenInstances[$id])) {
            throw ContainerException::frozenInstanceOverride($id);
        }

        $this->instances[$id] = $instance;
    }

    /**
     * Get a service instance, handling frozen state and factory/protected closures.
     */
    public function get(string $id, FactoryManager $factoryManager, Container $container): mixed
    {
        $this->frozenInstances[$id] = true;

        if (!is_object($this->instances[$id])
            || $factoryManager->isProtected($this->instances[$id])
            || !method_exists($this->instances[$id], '__invoke')
        ) {
            return $this->instances[$id];
        }

        if ($factoryManager->isFactory($this->instances[$id])) {
            return $this->instances[$id]($container);
        }

        $rawService = $this->instances[$id];

        /** @var mixed $resolvedService */
        $resolvedService = $rawService($container);

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

    /**
     * Check if an instance is frozen.
     */
    public function isFrozen(string $id): bool
    {
        return isset($this->frozenInstances[$id]);
    }

    /**
     * Get all registered service IDs.
     *
     * @return list<string>
     */
    public function getAll(): array
    {
        return array_keys($this->instances);
    }

    /**
     * Get raw instance for internal use (e.g., factory status transfer, extension).
     */
    public function getRaw(string $id): mixed
    {
        return $this->instances[$id] ?? null;
    }

    /**
     * Update instance directly (for lazy-loaded services).
     */
    public function update(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
    }
}
