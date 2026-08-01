<?php

declare(strict_types=1);

namespace GacelaTest\Fake\Attribute;

use Attribute;
use Gacela\Container\Attribute\Factory;

#[Attribute(Attribute::TARGET_CLASS)]
final class AppFactory extends Factory
{
}
