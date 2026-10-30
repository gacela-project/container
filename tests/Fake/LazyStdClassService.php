<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Lazy;
use stdClass;

#[Lazy]
class LazyStdClassService extends stdClass
{
    public function __construct(public ClassWithoutDependencies $dependency)
    {
        ConstructionCounter::record(static::class);
    }
}
