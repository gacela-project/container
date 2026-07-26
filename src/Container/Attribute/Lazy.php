<?php

declare(strict_types=1);

namespace Gacela\Container\Attribute;

use Attribute;

/**
 * Defers construction until the instance is first used.
 *
 * The container returns a lazy ghost: a real instance of the class, of the
 * right type, whose constructor has not run yet. Touching any property or
 * method initializes it. Useful for expensive services that a given request
 * may never reach.
 *
 * Requires PHP 8.4 for native lazy objects. On 8.3 the class is constructed
 * eagerly instead, which is unobservable apart from the timing.
 */
#[Attribute(Attribute::TARGET_CLASS)]
/**
 * @api
 */
final class Lazy
{
}
