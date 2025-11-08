<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class ServiceWithScalarDependency
{
    public function __construct(
        public Person $person,
        public string $apiKey,  // No default - will cause error
    ) {
    }
}
