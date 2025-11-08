<?php

declare(strict_types=1);

namespace Gacela\Container;

/**
 * Manages service name aliases with resolution caching.
 * Allows accessing the same service with multiple identifiers.
 */
final class AliasRegistry
{
    /** @var array<string,string> */
    private array $aliases = [];

    /** @var array<string,string> */
    private array $resolvedCache = [];

    /**
     * Create an alias for a service.
     */
    public function add(string $alias, string $id): void
    {
        $this->aliases[$alias] = $id;
        // Clear cached resolutions when aliases change
        $this->resolvedCache = [];
    }

    /**
     * Resolve an alias to its actual service ID with caching.
     * Returns the input if no alias exists.
     */
    public function resolve(string $id): string
    {
        if (isset($this->resolvedCache[$id])) {
            return $this->resolvedCache[$id];
        }

        $resolved = $this->aliases[$id] ?? $id;
        $this->resolvedCache[$id] = $resolved;

        return $resolved;
    }

    /**
     * Check if an alias exists.
     */
    public function has(string $alias): bool
    {
        return isset($this->aliases[$alias]);
    }
}
