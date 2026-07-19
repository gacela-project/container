<?php

declare(strict_types=1);

namespace Gacela\Container;

use Closure;
use Psr\Container\ContainerInterface as PsrContainerInterface;

/**
 * @psalm-type Binding = class-string|callable|object
 * @psalm-type BindingsMap = array<class-string, Binding>
 * @psalm-type ContextualBindingsMap = array<string, BindingsMap>
 */
interface ContainerInterface extends PsrContainerInterface
{
    /**
     * Get the resolved value of the instance.
     * Unless it is protected, in such a case it will get the raw instance as it was set.
     */
    public function get(string $id): mixed;

    /**
     * Like get(), but throws when the id resolves to null instead of returning it.
     */
    public function getOrFail(string $id): mixed;

    /**
     * Resolve a class to a typed, non-null instance.
     *
     * When $parameters are given, they override constructor arguments by
     * parameter name (top level only) and the instance is always built fresh.
     *
     * @template T of object
     *
     * @param class-string<T> $className
     * @param array<string, mixed> $parameters
     *
     * @return T
     */
    public function make(string $className, array $parameters = []): object;

    /**
     * Resolve the callable loading automatically all arguments based on current bindings.
     *
     * @param array<string, mixed> $parameters override arguments by parameter name
     */
    public function resolve(callable $callable, array $parameters = []): mixed;

    public function has(string $id): bool;

    /**
     * Register a binding from an abstract type to a concrete implementation.
     *
     * @param Binding $concrete
     */
    public function bind(string $abstract, string|callable|object $concrete): void;

    /**
     * Register a binding whose resolved instance is created once and reused.
     *
     * @param Binding|null $concrete when null, $abstract is the concrete class
     */
    public function singleton(string $abstract, string|callable|object|null $concrete = null): void;

    /**
     * Set a new instance. You cannot override an existing instance, but you can extend it.
     */
    public function set(string $id, mixed $instance): void;

    public function remove(string $id): void;

    /**
     * Ensure the instance is returning a new instance everytime.
     */
    public function factory(Closure $instance): Closure;

    /**
     * Extend the functionality of an instance, even before it is defined.
     */
    public function extend(string $id, Closure $instance): Closure;

    /**
     * Protect an instance to be resolved. A protected instance cannot be extended.
     */
    public function protect(Closure $instance): Closure;

    /**
     * @return list<string>
     */
    public function getRegisteredServices(): array;

    public function isFactory(string $id): bool;

    /**
     * Check if a service is frozen (has been accessed).
     */
    public function isFrozen(string $id): bool;

    /**
     * @return BindingsMap
     */
    public function getBindings(): array;

    /**
     * Pre-resolve and cache dependencies for the given class names.
     *
     * @param list<class-string> $classNames
     */
    public function warmUp(array $classNames): void;

    /**
     * Allow accessing the service registered as $id also under the name $alias.
     */
    public function alias(string $alias, string $id): void;

    /**
     * List all classes/interfaces that the given class depends on, recursively.
     *
     * @param class-string $className
     *
     * @return list<string>
     */
    public function getDependencyTree(string $className): array;
}
