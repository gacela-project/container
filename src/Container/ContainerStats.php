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
    public function __construct(
        public int $registeredServices,
        public int $frozenServices,
        public int $factoryServices,
        public int $bindings,
        public int $cachedDependencies,
        public int $memoryUsageBytes,
    ) {
    }

    public function memoryUsageFormatted(): string
    {
        return ByteFormatter::format($this->memoryUsageBytes);
    }
}
