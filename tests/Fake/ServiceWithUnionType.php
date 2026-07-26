<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class ServiceWithUnionType
{
    public function __construct(
        public int|string $value = 7,
    ) {
    }
}
