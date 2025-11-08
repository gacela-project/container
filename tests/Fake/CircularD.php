<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class CircularD
{
    public function __construct(
        public CircularE $e,
    ) {
    }
}
