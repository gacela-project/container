<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class DatabaseRepository implements RepositoryInterface
{
    public function __construct(
        public Person $person,
        public ClassWithoutDependencies $config,
    ) {
    }
}
