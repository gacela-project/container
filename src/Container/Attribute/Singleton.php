<?php

declare(strict_types=1);

namespace Gacela\Container\Attribute;

use Attribute;

/**
 * Marks a class as a singleton.
 * The container will cache and reuse a single instance.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Singleton
{
}
