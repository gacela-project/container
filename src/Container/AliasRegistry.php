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

    public function add(string $alias, string $id): void
    {
        $this->aliases[$alias] = $id;
        // Clear cached resolutions when aliases change
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
            return $id;
        }

        if (isset($this->resolvedCache[$id])) {
            return $this->resolvedCache[$id];
        }

        $resolved = $this->aliases[$id] ?? $id;
        $this->resolvedCache[$id] = $resolved;

        return $resolved;
    }

    public function has(string $alias): bool
    {
        return isset($this->aliases[$alias]);
    }
}
