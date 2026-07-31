<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Inject;

final class ServiceWithInjectedMethodNamingAnImplementation
{
    public ?RepositoryInterface $repository = null;

    #[Inject]
    public function setRepository(
        #[Inject(DatabaseRepository::class)]
        RepositoryInterface $repository,
    ): void {
        $this->repository = $repository;
    }
}
