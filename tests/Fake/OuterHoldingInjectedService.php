<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class OuterHoldingInjectedService
{
    public function __construct(
        public ServiceWithInjectedProperty $inner,
    ) {
    }
}
