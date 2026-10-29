<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class OuterHoldingUntypedMiddleService
{
    public function __construct(
        public ServiceWithUntypedMiddleParameter $inner,
    ) {
    }
}
