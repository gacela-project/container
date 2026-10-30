<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class ReplacementLazySingletonService extends LazySingletonService
{
    public function __construct()
    {
        ConstructionCounter::record(self::class);
    }
}
