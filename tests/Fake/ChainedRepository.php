<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

class ChainedRepository implements TertiaryRepositoryInterface
{
    public int $value = 0;

    public function __construct(int $value = 0)
    {
        $this->value = $value;
        ConstructionCounter::record(self::class);
    }
}
