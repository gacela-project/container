<?php

declare(strict_types=1);

namespace GacelaTest\Fake\Attribute;

use Attribute;
use Gacela\Container\Attribute\Inject;

/**
 * What a consumer re-presenting the container's attributes under its own
 * namespace looks like.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final class AppInject extends Inject
{
}
