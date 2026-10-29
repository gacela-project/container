<?php

declare(strict_types=1);

namespace Gacela\Container;

use Closure;
use WeakMap;

/**
 * @internal
 */
final class CompiledFactory
{
    /** @var WeakMap<Closure, list<class-string>>|null */
    private static ?WeakMap $dependencies = null;

    /**
     * @param Closure(): object $factory
     * @param list<class-string> $dependencies
     *
     * @return Closure(): object
     */
    public static function track(Closure $factory, array $dependencies): Closure
    {
        if (self::$dependencies === null) {
            /** @var WeakMap<Closure, list<class-string>> $dependenciesByFactory */
            $dependenciesByFactory = new WeakMap();
            self::$dependencies = $dependenciesByFactory;
        }
        self::$dependencies[$factory] = $dependencies;

        return $factory;
    }

    /**
     * @return list<class-string>
     */
    public static function dependenciesOf(mixed $factory): array
    {
        if (!$factory instanceof Closure || self::$dependencies === null) {
            return [];
        }

        $dependencies = self::$dependencies[$factory] ?? null;

        return $dependencies === null ? [] : $dependencies;
    }
}
