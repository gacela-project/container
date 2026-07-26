<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

/**
 * A scalar first, then a repeated object type, then a distinct one.
 *
 * Shaped to catch dependency-tree walkers that stop at the first skipped
 * parameter instead of continuing past it.
 */
final class ServiceWithMixedConstructor
{
    public function __construct(
        public string $label = 'x',
        public ?Person $first = null,
        public ?Person $second = null,
        public ?ClassWithoutDependencies $last = null,
    ) {
    }
}
