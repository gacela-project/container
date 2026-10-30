<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use App\Nope\Missing;
use Gacela\Container\Attribute\Inject;

final class MissingTypeInjectedConsumer
{
    #[Inject]
    public Missing $propertyDependency;

    public ?Missing $setterDependency = null;

    #[Inject]
    public function setDependency(Missing $dependency): void
    {
        $this->setterDependency = $dependency;
    }
}
