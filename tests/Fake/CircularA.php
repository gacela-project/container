<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class CircularA
{
    public function __construct(
        public CircularB $b,
    ) {
    }
}
