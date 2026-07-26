<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Lazy;

#[Lazy]
final class LazyService
{
    public function __construct(
        public ClassWithoutDependencies $dependency,
    ) {
        ConstructionCounter::record(self::class);
    }

    public function work(): string
    {
        return 'done';
    }
}
