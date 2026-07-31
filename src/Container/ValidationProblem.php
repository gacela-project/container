<?php

declare(strict_types=1);

namespace Gacela\Container;

/**
 * Why a class would fail to resolve.
 *
 * One case per thing validate() can prove ahead of time, so a build can assert
 * on the outcome rather than parse a message. The set is deliberately small:
 * these are the failures that are decidable without resolving, and nothing
 * else is claimed.
 *
 * @api
 */
enum ValidationProblem: string
{
    /**
     * The class does not exist, or could not be autoloaded.
     */
    case MissingClass = 'missing-class';

    /**
     * An interface, an abstract class, or a non-public constructor — reached as
     * a dependency with nothing bound to it.
     */
    case NotInstantiable = 'not-instantiable';

    /**
     * A constructor parameter nothing can supply: untyped, or a scalar with no
     * default and no contextual binding.
     */
    case UnresolvableParameter = 'unresolvable-parameter';

    /**
     * The class takes part in a constructor cycle, which resolution would throw
     * on.
     */
    case DependencyCycle = 'dependency-cycle';
}
