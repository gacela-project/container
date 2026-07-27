<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Inject;

final class ServiceWithInitializedInjectedProperty
{
    /**
     * Declared first on purpose: a static property must be skipped, not treated
     * as the end of the scan.
     */
    #[Inject]
    public static ?ClassWithoutDependencies $shared = null;

    #[Inject]
    public ?ClassWithoutDependencies $dependency = null;
}
