<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Inject;

final class ServiceWithUnboundInjectedMethod
{
    #[Inject]
    public function configure(RepositoryInterface $repository, PersonWithoutDefaultValues $person): void
    {
    }
}
