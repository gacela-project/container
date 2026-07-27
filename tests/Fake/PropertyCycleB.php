<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Inject;

final class PropertyCycleB
{
    #[Inject]
    public PropertyCycleA $a;
}
