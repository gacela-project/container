<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Inject;

final class ServiceWithScalarInjectedProperty
{
    #[Inject]
    public string $apiKey;
}
