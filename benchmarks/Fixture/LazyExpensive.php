<?php

declare(strict_types=1);

namespace GacelaBench\Fixture;

use Gacela\Container\Attribute\Lazy;

/**
 * An expensive branch of the graph that a request may never touch.
 */
#[Lazy]
final class LazyExpensive
{
    public function __construct(
        public Level1 $level1,
    ) {
    }
}
