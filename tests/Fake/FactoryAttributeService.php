<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Factory;

#[Factory]
final class FactoryAttributeService
{
    public function __construct(
        public ClassWithoutDependencies $dependency,
    ) {
    }
}
