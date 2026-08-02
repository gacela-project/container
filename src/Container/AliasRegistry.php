<?php

declare(strict_types=1);

namespace Gacela\Container;

/**
 * Manages service name aliases with resolution caching.
 * Allows accessing the same service with multiple identifiers.
 *
 * @internal
 * Not covered by backward compatibility: this class is an implementation
 * detail of Container and may change or disappear in any release
 */
final class AliasRegistry
{
    /** @var array<string,string> */
    private array $aliases = [];

    /** @var array<string,string> */
    private array $resolvedCache = [];

    private ?self $parent = null;

    /**
     * Let a scope see the aliases of the container it was created from.
     *
     * Resolution walks the chain here rather than in Container, so that an
     * ancestor's alias is applied *before* the local registries are consulted —
     * otherwise a scope shadowing the canonical id would be skipped whenever
     * the aliased name was used.
     */
    public function inheritFrom(self $parent): void
    {
        $this->parent = $parent;
    }

    public function add(string $alias, string $id): void
    {
        $this->aliases[$alias] = $id;
        $this->resolvedCache = [];
    }

    /**
     * Resolve an alias to its actual service ID; returns the input if no alias exists.
     */
    public function resolve(string $id): string
    {
        // Most containers register no aliases at all. Without this, every
        // distinct id resolved would write an identity entry into
        // resolvedCache, growing it unboundedly to map ids onto themselves.
        if ($this->aliases === []) {
            return $this->parent?->resolve($id) ?? $id;
        }

        if (isset($this->resolvedCache[$id])) {
            return $this->resolvedCache[$id];
        }

        if (isset($this->aliases[$id])) {
            return $this->resolvedCache[$id] = $this->aliases[$id];
        }

        // Only ids this registry maps are cached. An ancestor's answer is
        // cached by the ancestor, which is also where add() invalidates it.
        return $this->parent?->resolve($id) ?? $id;
    }
}
