<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class OuterHoldingDefaultedClassService
{
    public function __construct(
        public ServiceWithDefaultedClassDependency $inner,
    ) {
    }
}
