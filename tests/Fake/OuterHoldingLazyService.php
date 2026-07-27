<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class OuterHoldingLazyService
{
    public function __construct(
        public LazyService $lazyService,
    ) {
    }
}
