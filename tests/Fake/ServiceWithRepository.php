<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class ServiceWithRepository
{
    public function __construct(
        public RepositoryInterface $repository,
    ) {
    }
}
