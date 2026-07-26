<?php

declare(strict_types=1);

namespace GacelaBench\Fixture;

final class Level3
{
    public function __construct(
        public Level4 $level4,
    ) {
    }
}
