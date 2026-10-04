<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class OuterOfWithoutDefaults
{
    public function __construct(
        public PersonWithoutDefaultValues $inner,
    ) {
    }
}
