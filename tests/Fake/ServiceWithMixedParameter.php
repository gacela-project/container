<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class ServiceWithMixedParameter
{
    public function __construct(
        public mixed $config,
    ) {
    }
}
