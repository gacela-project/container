<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Throwable;

final class ServiceThrowingOnConstruction
{
    public function __construct(Throwable $failure)
    {
        throw $failure;
    }
}
