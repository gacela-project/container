<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class CircularB
{
    public function __construct(
        public CircularA $a,
    ) {
    }
}
