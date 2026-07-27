<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Inject;
use Gacela\Container\Attribute\Lazy;

#[Lazy]
final class LazyWithInjectedProperty
{
    #[Inject]
    public ClassWithoutDependencies $injected;

    public function __construct(
        public ClassWithoutDependencies $constructed,
    ) {
        ConstructionCounter::record(self::class);
    }
}
