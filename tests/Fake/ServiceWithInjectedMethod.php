<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Inject;

/**
 * The setter case #[Inject] on a method exists for: a dependency arriving after
 * construction, through a method that can do more than assign a field.
 */
final class ServiceWithInjectedMethod
{
    public ?ClassWithoutDependencies $dependency = null;

    public int $calls = 0;

    #[Inject]
    public function setDependency(ClassWithoutDependencies $dependency): void
    {
        $this->dependency = $dependency;
        ++$this->calls;
    }
}
