<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Inject;

class ParentDeclaringAnInjectedMethod
{
    public ?ClassWithoutDependencies $fromParent = null;

    #[Inject]
    public function setFromParent(ClassWithoutDependencies $dependency): void
    {
        $this->fromParent = $dependency;
    }
}
