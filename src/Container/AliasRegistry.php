<?php

declare(strict_types=1);

namespace Gacela\Container;

/**
 * Manages service name aliases.
 * Allows accessing the same service with multiple identifiers.
 */
final class AliasRegistry
{
    /** @var array<string,string> */
    private array $aliases = [];

    /**
     * Create an alias for a service.
     */
    public function add(string $alias, string $id): void
    {
        $this->aliases[$alias] = $id;
    }

    /**
     * Resolve an alias to its actual service ID.
     * Returns the input if no alias exists.
     */
    public function resolve(string $id): string
    {
        return $this->aliases[$id] ?? $id;
    }

    /**
     * Check if an alias exists.
     */
    public function has(string $alias): bool
    {
        return isset($this->aliases[$alias]);
    }
}
