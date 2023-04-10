<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class ClassWithObjectDependencies
{
    public function __construct(
        public Person $person,
    ) {
    }
}
