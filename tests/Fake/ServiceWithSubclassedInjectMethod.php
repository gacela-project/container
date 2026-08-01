<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use GacelaTest\Fake\Attribute\AppInject;

final class ServiceWithSubclassedInjectMethod
{
    public ?ClassWithoutDependencies $dependency = null;

    #[AppInject]
    public function setDependency(ClassWithoutDependencies $dependency): void
    {
        $this->dependency = $dependency;
    }
}
