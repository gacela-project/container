<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Inject;
use Gacela\Container\Attribute\Lazy;

#[Lazy]
final class LazyServiceWithoutConstructor
{
    public int $retries = 3;

    #[Inject]
    public ClassWithoutDependencies $propertyDependency;

    public ?ClassWithoutDependencies $setterDependency = null;

    public int $setterCalls = 0;

    #[Inject]
    public function setDependency(ClassWithoutDependencies $dependency): void
    {
        $this->setterDependency = $dependency;
        ++$this->setterCalls;
    }
}
