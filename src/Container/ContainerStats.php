<?php

declare(strict_types=1);

namespace Gacela\Container;

/**
 * A snapshot of what a container is holding.
 *
 * The typed counterpart to Container::getStats(). That method returns an array,
 * so adding or renaming a key would silently break consumers — which is why its
 * shape is carved out of the backward compatibility promise. Adding a property
 * to a readonly object is additive and safe, so this one carries no such
 * caveat: every property below is covered.
 *
 * Memory is exposed as an int rather than a preformatted string, so it can be
 * compared and summed without parsing "5.54 MB" back into a number.
 *
 * @api
 */
final readonly class ContainerStats
{
    /**
     * @param int $registeredServices ids this container holds an instance for
     * @param int $frozenServices of those, how many have been resolved and can
     *   no longer be overwritten
     * @param int $factoryServices of those, how many are factory closures
     * @param int $bindings abstract-to-concrete mappings registered here
     * @param int $cachedDependencies classes this container has resolved at
     *   least once
     * @param int $memoryUsageBytes **the whole PHP process**, not this
     *   container — memory_get_usage(true), the real memory the allocator has
     *   handed the process. It moves when anything anywhere allocates, and two
     *   containers in the same process report the same number. Every other
     *   field here is container-scoped, so this one is the odd one out: read it
     *   as ambient context for the counters beside it, never as what this
     *   container costs. Renamed to processMemoryBytes in 2.0, where the name
     *   will say so.
     */
    public function __construct(
        public int $registeredServices,
        public int $frozenServices,
        public int $factoryServices,
        public int $bindings,
        public int $cachedDependencies,
        public int $memoryUsageBytes,
    ) {
    }

    /**
     * memoryUsageBytes as a human-readable string. Process memory — see the
     * constructor.
     */
    public function memoryUsageFormatted(): string
    {
        return ByteFormatter::format($this->memoryUsageBytes);
    }
}
