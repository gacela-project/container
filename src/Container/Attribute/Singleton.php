<?php

declare(strict_types=1);

namespace Gacela\Container\Attribute;

use Attribute;

/**
 * Marks a class as a singleton.
 * The container will cache and reuse a single instance.
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
class Singleton
{
}
