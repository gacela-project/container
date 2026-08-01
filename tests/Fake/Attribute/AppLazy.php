<?php

declare(strict_types=1);

namespace GacelaTest\Fake\Attribute;

use Attribute;
use Gacela\Container\Attribute\Lazy;

#[Attribute(Attribute::TARGET_CLASS)]
final class AppLazy extends Lazy
{
}
