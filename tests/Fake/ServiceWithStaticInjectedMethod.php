<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Inject;

final class ServiceWithStaticInjectedMethod
{
    #[Inject]
    public static function setDependency(ClassWithoutDependencies $dependency): void
    {
    }
}
