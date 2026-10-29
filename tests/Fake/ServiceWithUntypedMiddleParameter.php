<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class ServiceWithUntypedMiddleParameter
{
    public function __construct(
        public ClassWithoutDependencies $first,
        public $middle = 'middle-default',
        public string $last = 'last-default',
    ) {
    }
}
