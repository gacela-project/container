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
 *
 * Not `final`: a consumer may subclass this to re-present it under its own
 * namespace. Every attribute read in the container passes
 * ReflectionAttribute::IS_INSTANCEOF, so the subclass is honoured — an exact
 * match follows neither a subclass nor a class_alias(), and the failure is
 * silent, the dependency simply never arriving.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Lazy
{
}
