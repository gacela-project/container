<?php

declare(strict_types=1);

namespace Gacela\Container\Attribute;

use Attribute;

/**
 * Marks a constructor parameter for dependency injection.
 * Optionally specifies which concrete implementation to inject.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Inject
{
    /**
     * @param class-string|null $implementation The specific implementation to inject
     */
    public function __construct(
        public ?string $implementation = null,
    ) {
    }
}
