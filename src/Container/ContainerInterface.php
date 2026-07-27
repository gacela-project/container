<?php

declare(strict_types=1);

namespace Gacela\Container;

use ArrayAccess;
use Closure;
use Override;
use Psr\Container\ContainerInterface as PsrContainerInterface;

/**
 * @psalm-type Binding = class-string|callable|object
 * @psalm-type BindingsMap = array<class-string, Binding>
 * @psalm-type ContextualBindingsMap = array<string, array<string, mixed>>
 * @psalm-type StatsArray = array{
 *     registered_services: int,
 *     frozen_services: int,
 *     factory_services: int,
 *     bindings: int,
 *     cached_dependencies: int,
 *     memory_usage: string
 * }
 *
 * @extends ArrayAccess<string, mixed>
 *
 * @api
 */
interface ContainerInterface extends PsrContainerInterface, ArrayAccess
{
    /**
     * Get the resolved value of the instance.
     * Unless it is protected, in such a case it will get the raw instance as it was set.
     */
    #[Override]
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

    #[Override]
    public function has(string $id): bool;

    /**
     * Register a callback to run after the given id is resolved, receiving the
     * resolved instance and the container. Callbacks run in registration order.
     */
    public function afterResolving(string $id, Closure $callback): void;

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
     * Whether a binding or instance is registered for the given id (alias-aware).
     */
    public function bound(string $id): bool;

    /**
     * Register a binding only if the abstract is not already bound.
     *
     * @param Binding $concrete
     */
    public function bindIf(string $abstract, string|callable|object $concrete): void;

    /**
     * Register a singleton binding only if the abstract is not already bound.
     *
     * @param Binding|null $concrete when null, $abstract is the concrete class
     */
    public function singletonIf(string $abstract, string|callable|object|null $concrete = null): void;

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
     * Group one or more service ids under a tag. Calls accumulate and dedupe.
     *
     * @param string|list<string> $ids
     */
    public function tag(string|array $ids, string $tag): void;

    /**
     * Lazily resolve every service registered under a tag, in insertion order.
     *
     * @return iterable<mixed>
     */
    public function tagged(string $tag): iterable;

    /**
     * List all classes/interfaces that the given class depends on, recursively.
     *
     * @param class-string $className
     *
     * @return list<string>
     */
    public function getDependencyTree(string $className): array;

    /**
     * Define a contextual binding, scoping a dependency to the classes that ask for it.
     *
     * @param class-string|list<class-string> $concrete
     */
    public function when(string|array $concrete): ContextualBindingBuilder;

    /**
     * Warm up the given classes and return their compiled constructor plans,
     * which can be fed back through the constructor to skip reflection.
     *
     * @param list<class-string> $classNames
     *
     * @return array<class-string, mixed>
     */
    public function compile(array $classNames): array;

    /**
     * Compile the given classes and write their constructor plans to an
     * opcache-friendly PHP file.
     *
     * Entries are stamped with the files they were compiled from, so a plan for
     * a constructor that has since changed is dropped when the file is loaded
     * rather than used. Container adds an optional third argument, a build
     * stamp; the signature here stays as it is, because widening an interface
     * 1.x promises not to extend would break every implementation of it.
     *
     * @param list<class-string> $classNames
     */
    public function writeCompiledCache(array $classNames, string $file): void;

    /**
     * Snapshot of container counters, for debugging and monitoring.
     *
     * The shape of the returned array is NOT covered by backward compatibility
     * and may change in any release. Do not build logic on it.
     *
     * Superseded by Container::stats(), which returns a ContainerStats whose
     * shape IS covered. This method is replaced by it in 2.0.
     *
     * @return StatsArray
     */
    public function getStats(): array;
}
