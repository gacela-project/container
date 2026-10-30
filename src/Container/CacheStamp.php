<?php

declare(strict_types=1);

namespace Gacela\Container;

use ReflectionClass;
use Throwable;

use function array_key_exists;
use function array_keys;
use function array_pop;
use function clearstatcache;
use function hash_file;
use function stat;

/**
 * @psalm-type FileStamp = array{string, int, int, string}
 * @psalm-type SourceStamp = list<FileStamp>
 *
 * @internal
 * Not covered by backward compatibility: this class is an implementation
 * detail of Container and may change or disappear in any release
 */
final class CacheStamp
{
    /**
     * @param class-string $class
     * @param list<class-string> $dependencies
     * @param array<string, FileStamp|null> $checked
     *
     * @return SourceStamp|null null when a user source cannot be stamped
     */
    public static function of(string $class, array $dependencies = [], array &$checked = []): ?array
    {
        try {
            $pending = [];
            foreach ([$class, ...$dependencies] as $dependency) {
                $pending[] = new ReflectionClass($dependency);
            }

            $seen = [];
            $files = [];
            while (($reflection = array_pop($pending)) !== null) {
                $name = $reflection->getName();
                if (isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;

                $file = $reflection->getFileName();
                if ($file === false) {
                    if (!$reflection->isInternal()) {
                        return null;
                    }
                } else {
                    $files[$file] = true;
                }

                $parent = $reflection->getParentClass();
                if ($parent !== false) {
                    $pending[] = $parent;
                }
                foreach ($reflection->getInterfaces() as $interface) {
                    $pending[] = $interface;
                }
                foreach ($reflection->getTraits() as $trait) {
                    $pending[] = $trait;
                }
            }
        } catch (Throwable) {
            return null;
        }

        $stamps = [];
        foreach (array_keys($files) as $file) {
            if (!array_key_exists($file, $checked)) {
                $checked[$file] = self::sourceStamp($file);
            }
            $stamp = $checked[$file];
            if ($stamp === null) {
                return null;
            }
            $stamps[] = $stamp;
        }

        return $stamps;
    }

    /**
     * A deleted file counts as changed: the entry is dropped and the class
     * falls back to reflection, which will report the real problem.
     *
     * @param SourceStamp|null $stamp
     * @param array<string, FileStamp|null> $checked
     */
    public static function isCurrent(?array $stamp, array &$checked = []): bool
    {
        if ($stamp === null) {
            return false;
        }

        foreach ($stamp as $expected) {
            $file = $expected[0];
            if (!array_key_exists($file, $checked)) {
                $checked[$file] = self::sourceStamp($file);
            }
            if ($checked[$file] !== $expected) {
                return false;
            }
        }

        return true;
    }
    /**
     * @return FileStamp|null
     */
    private static function sourceStamp(string $file): ?array
    {
        clearstatcache(true, $file);
        $stat = @stat($file);
        if ($stat === false) {
            return null;
        }
        $hash = @hash_file('sha256', $file);
        if ($hash === false) {
            return null;
        }

        return [$file, $stat['mtime'], $stat['size'], $hash];
    }

}
