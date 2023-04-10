<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class ClassWithInterfaceDependencies
{
    public function __construct(
        public PersonInterface $person,
    ) {
    }
}
