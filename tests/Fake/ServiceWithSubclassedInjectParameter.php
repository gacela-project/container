<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use GacelaTest\Fake\Attribute\AppInject;

final class ServiceWithSubclassedInjectParameter
{
    public function __construct(
        #[AppInject(DatabaseRepository::class)]
        public RepositoryInterface $repository,
    ) {
    }
}
