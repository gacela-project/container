<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Inject;

/**
 * Redeclares the parent's private property name. The two are separate storage
 * slots, so both must be injected — and each through its own declaring class.
 */
final class ShadowingChild extends ShadowingParent
{
    #[Inject]
    private ClassWithDependencyWithoutDependencies $shadowed;

    public function childShadowed(): ClassWithDependencyWithoutDependencies
    {
        return $this->shadowed;
    }
}
