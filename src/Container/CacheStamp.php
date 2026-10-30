<?php

declare(strict_types=1);

namespace Gacela\Container;

use ReflectionClass;
use Throwable;

use function array_keys;
use function array_pop;
use function stat;

/**
 * @psalm-type FileStamp = array{string, int, int}
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
     *
     * @return SourceStamp|null null when a user source cannot be stamped, or no class has a source file
     */
    public static function of(string $class, array $dependencies = []): ?array
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
            $stat = @stat($file);
            if ($stat === false) {
                return null;
            }
            $stamps[] = [$file, $stat['mtime'], $stat['size']];
        }

        return $stamps === [] ? null : $stamps;
    }

    /**
     * A deleted file counts as changed: the entry is dropped and the class
     * falls back to reflection, which will report the real problem.
     *
     * @param SourceStamp|null $stamp
     */
    public static function isCurrent(?array $stamp): bool
    {
        if ($stamp === null) {
            return true;
        }

        foreach ($stamp as [$file, $mtime, $size]) {
            $stat = @stat($file);
            if ($stat === false || $stat['mtime'] !== $mtime || $stat['size'] !== $size) {
                return false;
            }
        }

        return true;
    }
}
