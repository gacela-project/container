<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class ServiceWithDefaultedClassDependency
{
    public function __construct(
        public ?ClassWithoutDependencies $dependency = null,
    ) {
    }
}
