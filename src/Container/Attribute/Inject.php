<?php

declare(strict_types=1);

namespace Gacela\Container\Attribute;

use Attribute;

/**
 * Marks a constructor parameter, a property, or a setter for dependency
 * injection. Optionally specifies which concrete implementation to inject.
 *
 * Constructor injection remains the default. On a property or a method this
 * exists for classes whose constructor is not yours to change — framework base
 * classes and legacy code — not as an equal alternative, and not as a way to
 * model cycles: a cycle reached through either still raises
 * CircularDependencyException.
 *
 * A method is the option a property cannot cover: it can validate or derive
 * state, where writing the field cannot, and it can be declared on an interface,
 * where a property cannot.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
/**
 * @api
 */
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
