<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Singleton;

#[Singleton]
final class SingletonAttributeService
{
    public function __construct(
        public ClassWithoutDependencies $dependency,
    ) {
    }
}
