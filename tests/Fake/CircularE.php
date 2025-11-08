<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class CircularE
{
    public function __construct(
        public CircularC $c,
    ) {
    }
}
