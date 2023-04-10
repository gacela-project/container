<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class Person implements PersonInterface
{
    public function __construct(
        public string $name = '',
    ) {
    }
}
