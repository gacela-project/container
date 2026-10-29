<?php

declare(strict_types=1);

namespace Gacela\Container;

use Gacela\Container\Exception\ContainerException;

use function array_keys;

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
        if ($this->aliases === [] && $this->parent === null) {
            return $id;
        }

        if ($this->parent === null && isset($this->resolvedCache[$id])) {
            return $this->resolvedCache[$id];
        }

        $requested = $id;
        $visited = [];

        while (($target = $this->findAlias($id)) !== null) {
            if (isset($visited[$id])) {
                throw ContainerException::aliasCycle([...array_keys($visited), $id]);
            }

            $visited[$id] = true;
            $id = $target;
        }

        // A parent can add or change any remaining link after a scope resolves it.
        if ($this->parent === null && $visited !== []) {
            $this->resolvedCache[$requested] = $id;
        }

        return $id;
    }

    private function findAlias(string $id): ?string
    {
        $registry = $this;

        while ($registry !== null) {
            if (isset($registry->aliases[$id])) {
                return $registry->aliases[$id];
            }

            $registry = $registry->parent;
        }

        return null;
    }
}
