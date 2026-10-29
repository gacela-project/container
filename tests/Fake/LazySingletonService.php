<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Lazy;
use Gacela\Container\Attribute\Singleton;

#[Singleton]
#[Lazy]
class LazySingletonService
{
    public int $value = 0;

    public function __construct()
    {
        ConstructionCounter::record(self::class);
    }
}
