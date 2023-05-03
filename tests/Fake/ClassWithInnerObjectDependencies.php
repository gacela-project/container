<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class ClassWithInnerObjectDependencies
{
    public function __construct(
        public ClassWithRelationship $classWithRelationship,
    ) {
    }
}
