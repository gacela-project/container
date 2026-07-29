<?php

declare(strict_types=1);

namespace Gacela\Container;

use Gacela\Container\Exception\ContainerException;

/**
 * The whole of what a Container does, as a contract.
 *
 * `ContainerInterface` is frozen for 1.x — nothing will be added to it — which
 * is the right promise and has a cost: eleven methods, most of the 1.2–1.4
 * feature set, are reachable only through the concrete `final class Container`.
 * Following the library's own advice and depending on the interface therefore
 * costs you scopes, definitions-as-data, lazy registration, keyed tags, typed
 * stats and every part of compiled factories.
 *
 * This interface is the 1.x answer, and it is purely additive: `ContainerInterface`
 * is untouched, so no existing implementor of it is affected and the promise
 * holds literally. Depend on this when you want the full surface without
 * typehinting the concrete class:
 *
 * ```php
 * public function __construct(
 *     private FullContainerInterface $container,
 * ) {
 * }
 * ```
 *
 * The value is compile-time enforcement for decorators. A container that wraps
 * another and forwards to it can implement this instead of hand-writing eleven
 * forwarders that nothing checks — a method added upstream then fails
 * compilation there rather than silently going missing.
 *
 * At 2.0 these methods move onto `ContainerInterface` itself, and this name
 * stays as a deprecated alias. Nothing that depends on it now has to migrate
 * twice.
 *
 * @psalm-import-type StatsArray from ContainerInterface
 *
 * @api
 */
interface FullContainerInterface extends ContainerInterface
{
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
     *
     * @throws ContainerException when an entry names an unknown key, or a key's
     *                            value is not of the type it accepts
     */
    public function load(array $definitions): void;

    /**
     * Load definitions from a '.php' file returning an array, or a '.json' file.
     *
     * @throws ContainerException when the file is missing, unreadable, of an
     *                            unsupported type, or does not hold an array
     */
    public function loadFile(string $file): void;

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
}
