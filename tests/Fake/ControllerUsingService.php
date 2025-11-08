<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class ControllerUsingService
{
    public function __construct(
        public ServiceWithScalarDependency $service,
    ) {
    }
}
