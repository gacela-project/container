<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class ConsumerOfParentMethodService
{
    public function __construct(public ParentDeclaringAnInjectedMethod $service)
    {
    }
}
