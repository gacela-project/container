<?php

declare(strict_types=1);

namespace Gacela\Container;

use ReflectionClass;
use Throwable;

use function stat;

/**
 * What a compiled entry was compiled from, and whether that is still true.
 *
 * A plan describes a constructor signature. Nothing in the plan says which
 * signature, so a plan written before a constructor changed reads as current
 * and the container builds with the old argument list. The stamp closes that
 * gap: the declaring file's path, mtime and size, taken at build time and
 * compared once on read.
 *
 * The file rather than the signature, because verifying a signature means
 * reflecting the class — precisely the work the cache exists to avoid. A stat
 * costs one syscall and cannot be fooled by an edit, which is the only thing
 * that can invalidate a plan in the first place.
 *
 * A null stamp means "nothing to compare": an internal class, or one whose
 * file could not be read when the cache was written. Those are treated as
 * current, since the alternative is discarding entries no edit can invalidate.
 *
 * @psalm-type FileStamp = array{string, int, int}
 *
 * @internal
 * Not covered by backward compatibility: this class is an implementation
 * detail of Container and may change or disappear in any release
 */
final class CacheStamp
{
    /**
     * @param class-string $class
     *
     * @return FileStamp|null null when the class has no readable file of its own
     */
    public static function of(string $class): ?array
    {
        try {
            $file = (new ReflectionClass($class))->getFileName();
        } catch (Throwable) {
            return null;
        }

        if ($file === false) {
            return null;
        }

        $stat = @stat($file);

        if ($stat === false) {
            return null;
        }

        return [$file, $stat['mtime'], $stat['size']];
    }

    /**
     * A deleted file counts as changed: the entry is dropped and the class
     * falls back to reflection, which will report the real problem.
     *
     * @param FileStamp|null $stamp
     */
    public static function isCurrent(?array $stamp): bool
    {
        if ($stamp === null) {
            return true;
        }

        [$file, $mtime, $size] = $stamp;

        $stat = @stat($file);

        return $stat !== false
            && $stat['mtime'] === $mtime
            && $stat['size'] === $size;
    }
}
