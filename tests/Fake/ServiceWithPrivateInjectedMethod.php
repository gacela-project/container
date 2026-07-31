<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Inject;

final class ServiceWithPrivateInjectedMethod
{
    #[Inject]
    private function setDependency(ClassWithoutDependencies $dependency): void
    {
    }
}
