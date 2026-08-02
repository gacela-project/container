<?php

declare(strict_types=1);

namespace Gacela\Container;

use Closure;

use function is_array;
use function is_object;
use function is_string;
use function spl_object_id;

/**
 * A stable cache key for a callable.
 *
 * Object-bound callables include the object id, so two instances of the same
 * class never share a key.
 *
 * @internal
 * Not covered by backward compatibility: this class is an implementation
 * detail of Container and may change or disappear in any release
 */
final class CallableKey
{
    public static function for(callable $callable): string
    {
        if (is_array($callable)) {
            $classOrObject = $callable[0];
            $method = $callable[1];

            return self::identify($classOrObject) . '::' . $method;
        }

        if (is_string($callable)) {
            return $callable;
        }

        // Only closures and invokable objects remain once array and string are
        // ruled out.
        /** @var callable&object $callable */
        return self::identify($callable);
    }

    /**
     * A key for the parameter *plan* of a callable, or null when it has no
     * stable name to key on and the object itself has to serve as the identity.
     *
     * Deliberately coarser than for(): a plan describes a signature, and every
     * instance of a class reaches the same method with the same parameters, so
     * mixing in the object would key one plan per instance and never hit.
     *
     * Deliberately not spl_object_id-based either. PHP reuses an id once an
     * object is collected, so a freshly created callable could inherit the plan
     * — and therefore the parameter list — of a dead one with a different
     * signature. That is harmless while the id only feeds a counter, and a wrong
     * argument list once it keys a cache.
     */
    public static function signatureFor(callable $callable): ?string
    {
        if (is_array($callable)) {
            return self::nameOf($callable[0]) . '::' . $callable[1];
        }

        if (is_string($callable)) {
            return $callable;
        }

        // Only closures and invokable objects remain once array and string are
        // ruled out.
        /** @var callable&object $callable */
        return $callable instanceof Closure
            ? null
            : $callable::class . '::__invoke';
    }

    private static function nameOf(object|string $classOrObject): string
    {
        return is_object($classOrObject) ? $classOrObject::class : $classOrObject;
    }

    private static function identify(object|string $classOrObject): string
    {
        if (is_object($classOrObject)) {
            return $classOrObject::class . '#' . spl_object_id($classOrObject);
        }

        return $classOrObject;
    }
}
