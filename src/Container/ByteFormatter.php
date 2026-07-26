<?php

declare(strict_types=1);

namespace Gacela\Container;

use function count;

/**
 * Formats a byte count for human consumption.
 *
 * @internal
 * Not covered by backward compatibility: this class is an implementation
 * detail of Container and may change or disappear in any release
 */
final class ByteFormatter
{
    /** @var list<string> */
    private const array UNITS = ['B', 'KB', 'MB', 'GB'];

    public static function format(int $bytes): string
    {
        $bytes = max($bytes, 0);

        $pow = $bytes > 0 ? (int) floor(log($bytes) / log(1024)) : 0;
        // Clamp both ends: the upper bound stops us running off the units
        // list, the lower one keeps the offset provably valid.
        $pow = max(0, min($pow, count(self::UNITS) - 1));

        $scaled = $bytes / (1 << (10 * $pow));

        return (string) round($scaled, 2) . ' ' . self::UNITS[$pow];
    }
}
