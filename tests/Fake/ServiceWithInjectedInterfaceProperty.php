<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Inject;

final class ServiceWithInjectedInterfaceProperty
{
    #[Inject(DatabaseRepository::class)]
    public RepositoryInterface $repository;
}
