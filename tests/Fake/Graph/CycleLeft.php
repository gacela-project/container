<?php

declare(strict_types=1);

namespace GacelaTest\Fake\Graph;

final class CycleLeft
{
    public function __construct(
        public CycleShared $shared,
    ) {
    }
}
