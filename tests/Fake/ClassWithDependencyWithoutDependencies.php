<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class ClassWithDependencyWithoutDependencies
{
    public function __construct(
        public ClassWithoutDependencies $classWithoutDependencies,
    ) {
    }
}
