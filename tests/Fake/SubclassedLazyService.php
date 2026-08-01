<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use GacelaTest\Fake\Attribute\AppLazy;

#[AppLazy]
final class SubclassedLazyService
{
    public function __construct(
        public ClassWithoutDependencies $dependency,
    ) {
        ConstructionCounter::record(self::class);
    }
}
