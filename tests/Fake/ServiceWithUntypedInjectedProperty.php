<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Inject;

final class ServiceWithUntypedInjectedProperty
{
    /** @var mixed */
    #[Inject]
    public $dependency;
}
