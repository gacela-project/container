<?php

declare(strict_types=1);

namespace Gacela\Container\Attribute;

use Attribute;

/**
 * Marks a class as a factory.
 * The container will create a new instance every time it's requested.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Factory
{
}
