<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Inject;

class ShadowingParent
{
    #[Inject]
    private ClassWithoutDependencies $shadowed;

    public function parentShadowed(): ClassWithoutDependencies
    {
        return $this->shadowed;
    }
}
