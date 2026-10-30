<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Lazy;

#[Lazy]
final class DeepLazyArrayBag extends LazyArrayBag
{
}
