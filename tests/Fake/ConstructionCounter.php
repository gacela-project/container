<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

/**
 * Counts constructions so tests can prove a lazy service was not built yet.
 */
final class ConstructionCounter
{
    /** @var array<string, int> */
    public static array $counts = [];

    public static function reset(): void
    {
        self::$counts = [];
    }

    public static function record(string $class): void
    {
        self::$counts[$class] = (self::$counts[$class] ?? 0) + 1;
    }

    public static function countFor(string $class): int
    {
        return self::$counts[$class] ?? 0;
    }
}
