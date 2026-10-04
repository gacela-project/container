<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Countable;

final class ClassWithIntersectionParameter
{
    public function __construct(
        public PersonInterface&Countable $value,
    ) {
    }
}
