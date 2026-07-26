<?php

declare(strict_types=1);

namespace GacelaBench\Fixture;

final class Level2
{
    public function __construct(
        public Level3 $level3,
    ) {
    }
}
