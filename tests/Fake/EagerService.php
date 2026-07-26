<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class EagerService
{
    public function __construct(
        public ClassWithoutDependencies $dependency,
    ) {
        ConstructionCounter::record(self::class);
    }
}
