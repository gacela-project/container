<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class ClassWithRelationship
{
    public function __construct(
        public Person $person1,
        public Person $person2,
    ) {
    }
}
