<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Inject;

class ParentWithPrivateInjectedProperty
{
    #[Inject]
    protected ClassWithoutDependencies $protectedDependency;
    #[Inject]
    private ClassWithoutDependencies $privateDependency;

    public function privateDependency(): ClassWithoutDependencies
    {
        return $this->privateDependency;
    }

    public function protectedDependency(): ClassWithoutDependencies
    {
        return $this->protectedDependency;
    }
}
