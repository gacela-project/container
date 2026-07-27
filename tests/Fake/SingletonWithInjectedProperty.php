<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Inject;
use Gacela\Container\Attribute\Singleton;

#[Singleton]
final class SingletonWithInjectedProperty
{
    #[Inject]
    public ClassWithoutDependencies $dependency;
}
