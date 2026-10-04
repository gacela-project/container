<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Lazy;

/**
 * No property for a lazy object to defer, so PHP would treat a ghost of it as
 * initialized at once and skip the constructor.
 */
#[Lazy]
final class LazyServiceWithoutProperties
{
    public function __construct(ClassWithoutDependencies $dependency)
    {
        ConstructionCounter::record(self::class);
    }
}
