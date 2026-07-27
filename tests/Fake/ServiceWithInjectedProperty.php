<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Inject;

final class ServiceWithInjectedProperty
{
    #[Inject]
    private ClassWithoutDependencies $dependency;

    public function dependency(): ClassWithoutDependencies
    {
        return $this->dependency;
    }
}
