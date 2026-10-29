<?php

declare(strict_types=1);

namespace Gacela\Container;

use function clearstatcache;
use function dirname;
use function function_exists;
use function posix_geteuid;
use function realpath;
use function stat;
use function tempnam;
use function unlink;

/**
 * @internal
 */
final class CacheFile
{
    public static function trustedPath(string $file): ?string
    {
        clearstatcache(true, $file);
        $path = realpath($file);
        if ($path === false) {
            return null;
        }

        clearstatcache(true, $path);
        $metadata = @stat($path);
        if ($metadata === false || ($metadata['mode'] & 0o170000) !== 0o100000) {
            return null;
        }

        if (function_exists('posix_geteuid') && $metadata['uid'] !== posix_geteuid()) {
            return null;
        }

        if (PHP_OS_FAMILY !== 'Windows'
            && (($metadata['mode'] & 0o022) !== 0 || !self::isTrustedDirectory(dirname($path)))
        ) {
            return null;
        }

        return $path;
    }

    public static function createTemporary(string $directory): string|false
    {
        clearstatcache(true, $directory);
        $directory = realpath($directory);
        if ($directory === false || !self::isTrustedDirectory($directory)) {
            return false;
        }

        // tempnam() creates the file with mode 0600, before any PHP is written.
        $temporary = @tempnam($directory, 'gacela-');
        if ($temporary === false) {
            return false;
        }

        // tempnam() can fall back to the system directory when creation fails.
        if (dirname($temporary) !== $directory) {
            @unlink($temporary);

            return false;
        }

        return $temporary;
    }

    private static function isTrustedDirectory(string $directory): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return true;
        }

        $owner = function_exists('posix_geteuid') ? posix_geteuid() : null;
        while (true) {
            clearstatcache(true, $directory);
            $metadata = @stat($directory);
            if ($metadata === false || ($metadata['mode'] & 0o170000) !== 0o040000) {
                return false;
            }

            if ($owner !== null && $metadata['uid'] !== 0 && $metadata['uid'] !== $owner) {
                return false;
            }

            if (($metadata['mode'] & 0o022) !== 0 && ($metadata['mode'] & 0o1000) === 0) {
                return false;
            }

            $parent = dirname($directory);
            if ($parent === $directory) {
                return true;
            }

            $directory = $parent;
        }
    }
}
