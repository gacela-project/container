<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class OtherConsumerOfSingletonAttributeService
{
    public function __construct(public SingletonAttributeService $service)
    {
    }
}
