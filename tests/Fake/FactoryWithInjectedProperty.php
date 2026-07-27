<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Factory;
use Gacela\Container\Attribute\Inject;

#[Factory]
final class FactoryWithInjectedProperty
{
    #[Inject]
    public ClassWithoutDependencies $dependency;
}
