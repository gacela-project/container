<?php

declare(strict_types=1);

namespace GacelaBench\Fixture;

/**
 * The same graph without #[Lazy], for comparison.
 */
final class EagerExpensive
{
    public function __construct(
        public Level1 $level1,
    ) {
    }
}
