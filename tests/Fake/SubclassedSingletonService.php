<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use GacelaTest\Fake\Attribute\AppSingleton;

#[AppSingleton]
final class SubclassedSingletonService
{
}
