<?php

declare(strict_types=1);

namespace Gacela\Container;

use ArrayAccess;

use Closure;
use Gacela\Container\Exception\ContainerException;
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
     * A map gives the entries keys, addressable with Container::taggedByKey().
     * The runtime signature is unchanged — this widens what an implementation
     * is asked to accept, not what it is asked to declare.
     *
     * @param string|array<array-key, string> $ids
     */
    public function tag(string|array $ids, string $tag): void;

    /**
     * Lazily resolve every service registered under a tag, in insertion order.
     *
     * Keyed entries come back under their key, unkeyed ones under their
     * position.
     *
     * @return iterable<array-key, mixed>
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
     * stamp; the signature here stays as it is, because widening it would break
     * every implementation of the interface.
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

    /**
     * A child container that resolves everything this one resolves, plus
     * whatever is registered on it directly.
     *
     * Nothing is copied. The scope starts empty and falls through to its parent
     * on a miss, so creating one costs the same whether the parent holds three
     * registrations or three thousand. Anything registered on the scope shadows
     * the parent for that scope alone and never mutates it.
     *
     * Lifetime follows first resolution: a service the parent has already
     * resolved is shared with every scope, and one a scope resolves first
     * belongs to that scope and goes away with it.
     *
     * Returns `static` rather than `self` so an implementor is bound to its own
     * type without being bound to this one — a decorator's scope is a decorator.
     */
    public function createScope(): static;

    /**
     * Whether this container, or one of its ancestors, already owns something
     * for $id: a binding, a stored instance, or a singleton it has resolved.
     *
     * Narrower than has(), which is also true of anything merely autowirable,
     * and wider than bound(), which does not see a singleton that no binding
     * introduced.
     */
    public function provides(string $id): bool;

    /**
     * A snapshot of what this container is holding.
     *
     * Prefer this over getStats(): the returned object's shape is covered by
     * backward compatibility, where the array's explicitly is not, and memory
     * comes back as an int rather than a string needing to be parsed.
     */
    public function stats(): ContainerStats;

    /**
     * Register services from data instead of code.
     *
     * Every entry ends up calling the registration method it stands for, so
     * laziness, freezing and scope behaviour are exactly what the imperative
     * equivalent would give you. Later calls override earlier keys, except for
     * 'tags', which accumulate the way tag() does.
     *
     * @param array<array-key, mixed> $definitions
     * @param (callable(string): void)|null $onRegistered called with each id as
     *   it is registered, for a listener that wants them one at a time
     *
     * @throws ContainerException when an entry names an unknown key, or a key's
     *                            value is not of the type it accepts
     *
     * @return list<string> every id registered, in definition order
     */
    public function load(array $definitions, ?callable $onRegistered = null): array;

    /**
     * Load definitions from a '.php' file returning an array, a '.json' file, or
     * a '.yaml'/'.yml' one when a YAML parser is installed.
     *
     * @param (callable(string): void)|null $onRegistered see load()
     *
     * @throws ContainerException when the file is missing, unreadable, of an
     *                            unsupported type, or does not hold an array
     *
     * @return list<string> every id registered, in definition order
     */
    public function loadFile(string $file, ?callable $onRegistered = null): array;

    /**
     * Defer the construction of a service until it is first used, without
     * putting #[Lazy] on the class.
     *
     * The two string forms return a lazy ghost, exactly like the attribute. The
     * closure form returns a lazy proxy instead: the closure, not the
     * constructor, produces the instance. The target must be a concrete class
     * either way.
     */
    public function lazy(string $abstract, string|callable|null $concrete = null): void;

    /**
     * The service registered under $key within $tag.
     *
     * Only the entry asked for is built, and it comes from the container's own
     * cache — a keyed tag is a lookup table of ids, never a second place
     * instances are kept. An unknown key throws naming the keys that exist.
     *
     * @throws ContainerException when $tag has no entry for $key
     */
    public function taggedByKey(string $tag, string $key): mixed;

    /**
     * The keys $tag can be asked for, in insertion order. Entries registered
     * without a key are not listed: there is no key to ask with.
     *
     * @return list<string>
     */
    public function taggedKeys(string $tag): array;

    /**
     * The dependency graph rooted at $className, as a tree.
     *
     * `getDependencyTree()` on ContainerInterface answers "what does this
     * touch" with a flat, deduplicated list. This keeps what flattening
     * removes: depth, which constructor parameter asked for what, the same
     * class appearing under several parents, and where a cycle closes.
     *
     * A cycle is marked on the node and cut, not thrown — inspecting a broken
     * graph is precisely when this is reached for. Bindings are resolved as the
     * graph is built, so an interface appears as its concrete.
     *
     * @param class-string $className
     */
    public function dependencyGraph(string $className): DependencyNode;

    /**
     * What writeCompiledFactories() would make of these classes, and why it
     * refuses the ones it refuses.
     *
     * Nothing is written. Its compiled() set is exactly what
     * writeCompiledFactories() returns for the same input.
     *
     * @param list<class-string> $classNames
     */
    public function compileReport(array $classNames): CompilationReport;

    /**
     * Write plain `new` expressions for the classes whose construction is
     * fully knowable ahead of time.
     *
     * Deliberately conservative: anything depending on a binding, a scalar, an
     * attribute or a contextual binding is left out and keeps resolving
     * normally. The file is an optimisation, never a second resolver.
     *
     * @param list<class-string> $classNames
     * @param string|null $buildStamp identifies the build this file belongs to
     *
     * @return list<class-string> the classes that were compiled
     */
    public function writeCompiledFactories(array $classNames, string $file, ?string $buildStamp = null): array;

    /**
     * Use previously generated factories as a fast path for get()/make().
     *
     * A later bind() or singleton() for one of these classes still outranks the
     * generated expression.
     *
     * @param array<class-string, callable(): object> $factories
     */
    public function useCompiledFactories(array $factories): void;

    /**
     * Hand $facade to service closures instead of this container.
     *
     * A decorator composes rather than extends, so without this every user
     * closure receives the inner container and misses whatever the decorator
     * added. See Container::withSelfReference().
     */
    public function withSelfReference(self $facade): self;

    /**
     * Prove these classes resolve, without resolving them.
     *
     * @param list<class-string>|ClassSource $classNames
     */
    public function validate(array|ClassSource $classNames): ValidationReport;
}
