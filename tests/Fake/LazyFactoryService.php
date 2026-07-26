<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Factory;
use Gacela\Container\Attribute\Lazy;

#[Factory]
#[Lazy]
final class LazyFactoryService
{
    public function __construct()
    {
        ConstructionCounter::record(self::class);
    }
}
