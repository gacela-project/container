<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Inject;

final class ServiceWithUnboundInjectedProperty
{
    #[Inject]
    public RepositoryInterface $repository;

    #[Inject]
    public PersonWithoutDefaultValues $person;
}
