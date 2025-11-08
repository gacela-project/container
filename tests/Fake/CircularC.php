<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class CircularC
{
    public function __construct(
        public CircularD $d,
    ) {
    }
}
