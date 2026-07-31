<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Inject;

final class ServiceWithInjectedMethodTakingAScalar
{
    #[Inject]
    public function setDsn(string $dsn): void
    {
    }
}
