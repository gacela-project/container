<?php

declare(strict_types=1);

namespace GacelaBench\Fixture;

/** Four levels deep, to measure recursive resolution cost. */
final class Level1
{
    public function __construct(
        public Level2 $level2,
    ) {
    }
}
