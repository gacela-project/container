<?php

declare(strict_types=1);

namespace Gacela\Container;

use function get_class;
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

    private static function identify(mixed $classOrObject): string
    {
        if (is_object($classOrObject)) {
            return get_class($classOrObject) . '#' . spl_object_id($classOrObject);
        }

        /** @var string $classOrObject */
        return $classOrObject;
    }
}
