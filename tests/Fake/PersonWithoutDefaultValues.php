<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class PersonWithoutDefaultValues
{
    public function __construct(
        public string $name,
    ) {
    }
}
