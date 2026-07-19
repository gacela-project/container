<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class ServiceWithMixedDependency
{
    public function __construct(
        public mixed $value,
    ) {
    }
}
