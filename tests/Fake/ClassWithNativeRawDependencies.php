<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class ClassWithNativeRawDependencies
{
    public function __construct(
        public string $name,
        public int $age,
    ) {
    }
}
