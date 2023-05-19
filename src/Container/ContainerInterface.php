<?php

declare(strict_types=1);

namespace Gacela\Container;

use Closure;
use Psr\Container\ContainerInterface as PsrContainerInterface;

interface ContainerInterface extends PsrContainerInterface
{
    /**
     * Get the resolved value of the instance.
     * Unless it is protected, in such a case it will get the raw instance as it was set.
     */
    public function get(string $id): mixed;

    /**
     * Resolve the callable loading automatically all arguments based on current bindings.
     */
    public function resolve(callable $callable): mixed;

    /**
     * Check if an instance exists.
     */
    public function has(string $id): bool;

    /**
     * Set a new instance. You cannot override an existing instance, but you can extend it.
     */
    public function set(string $id, mixed $instance): void;

    /**
     * Remove a known instance.
     */
    public function remove(string $id): void;

    /**
     * Ensure the instance is returning a new instance everytime.
     */
    public function factory(Closure $instance): object;

    /**
     * Extend the functionality of an instance, even before it is defined.
     */
    public function extend(string $id, Closure $instance): Closure;

    /**
     * Protect an instance to be resolved. A protected instance cannot be extended.
     */
    public function protect(Closure $instance): object;
}
