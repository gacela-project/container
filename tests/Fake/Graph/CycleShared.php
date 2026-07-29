<?php

declare(strict_types=1);

namespace GacelaTest\Fake\Graph;

final class CycleShared
{
    public function __construct(
        public CycleLeft $left,
    ) {
    }
}
