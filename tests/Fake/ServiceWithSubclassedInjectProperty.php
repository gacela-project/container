<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use GacelaTest\Fake\Attribute\AppInject;

final class ServiceWithSubclassedInjectProperty
{
    #[AppInject]
    public ClassWithoutDependencies $dependency;
}
