<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class ClassWithUnionParameter
{
    public function __construct(
        public int|string $value,
    ) {
    }
}
