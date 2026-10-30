<?php

declare(strict_types=1);

namespace Gacela\Container;

use Gacela\Container\Exception\ContainerException;
use Throwable;
use UnitEnum;

use function basename;
use function dirname;
use function file_put_contents;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function is_object;
use function is_readable;
use function is_string;
use function is_writable;
use function rename;
use function unlink;
use function var_export;

/**
 * Turns constructor plans into an opcache-friendly PHP file, and reads them
 * back.
 *
 * Kept off Container: generating code and touching the filesystem is a
 * build-time concern, not something a runtime container should carry.
 *
 * The file is an envelope — a format marker, an optional build stamp, one
 * CacheStamp per entry, and the entries themselves — because an entry that
 * cannot say what it was compiled from cannot be known to be stale, and a
 * stale plan is worse than no plan. The envelope is itself a plain
 * array, with optional constructor helpers for large generated graphs.
 *
 * @psalm-import-type CompiledPlans from PlanRegistry
 * @psalm-import-type FactoriesMap from ContainerInterface
 * @psalm-import-type SourceStamp from CacheStamp
 *
 * @internal
 * Not covered by backward compatibility: this class is an implementation
 * detail of Container and may change or disappear in any release
 */
final class CompiledCacheWriter
{
    // Format changes also invalidate plans written under older provenance rules.
    public const FORMAT = 4;

    /**
     * Classes whose default values cannot be statically exported are skipped
     * and fall back to reflection at runtime, so correctness never depends on
     * the cache being complete.
     *
     * @param CompiledPlans $plans
     * @param string|null $buildStamp identifies the build this file belongs to;
     *   see read() for what it buys
     * @param bool $releaseBound user-class plans require a matching non-empty build stamp
     */
    public static function write(array $plans, string $file, ?string $buildStamp = null, bool $releaseBound = false): void
    {
        $entries = [];
        $stamps = [];
        $checked = [];

        foreach ($plans as $class => $plan) {
            if (!self::isExportable($plan)) {
                continue;
            }

            try {
                $exportedPlan = var_export($plan, true);
            } catch (Throwable) {
                continue;
            }

            $stamp = CacheStamp::of($class, [], $checked);
            if ($stamp === null) {
                continue;
            }

            if ($releaseBound && $stamp !== [] && ($buildStamp === null || $buildStamp === '')) {
                continue;
            }

            $entries[] = '        ' . var_export($class, true) . ' => ' . $exportedPlan . ',';
            $stamps[$class] = $releaseBound && $stamp !== [] ? null : $stamp;
        }

        self::put($file, self::envelope($buildStamp, $stamps, 'plans', $entries));
    }

    /**
     * Entries whose source files changed since the cache was written are
     * dropped, so a stale entry behaves exactly like a missing one and the
     * class falls back to reflection.
     *
     * $buildStamp is the escape hatch for large maps, where checking source files
     * costs more than the reflection it replaces. Pass the same value here and
     * at write time — a deploy id, a commit sha — and the whole file is taken
     * on that single comparison, with no per-entry stat at all. A stamp that
     * does not match discards the file wholesale. Omit it, or write without
     * one, and every entry is checked individually.
     *
     * @throws ContainerException when the file is missing, unreadable, or is
     *                            not a cache this version can read
     *
     * @return CompiledPlans
     */
    public static function read(string $file, ?string $buildStamp = null): array
    {
        /** @var CompiledPlans $plans */
        $plans = self::entries($file, 'plans', $buildStamp);

        return $plans;
    }

    /**
     * The same reading, for the `new` expressions writeCompiledFactories()
     * generates. A generated expression pins an argument list just as a plan
     * does, so it goes stale for exactly the same reason.
     *
     * @throws ContainerException
     *
     * @return FactoriesMap
     */
    public static function readFactories(string $file, ?string $buildStamp = null): array
    {
        /** @var FactoriesMap $factories */
        $factories = self::entries($file, 'factories', $buildStamp);

        return $factories;
    }

    /**
     * Writing must never fail quietly: a build step that reports success while
     * producing nothing leaves production silently unoptimised, or fatally
     * broken at load time.
     *
     * @throws ContainerException
     */
    public static function put(string $file, string $code): void
    {
        // Checked up front rather than leaning on @-suppression: an
        // application with its own error handler still sees the warning, and
        // would get an ErrorException from inside the container instead of
        // this exception.
        if (!self::isWritable($file)) {
            throw ContainerException::compiledCacheNotWritable($file);
        }

        // Written beside the target and renamed over it: a request that
        // includes the file while it is being written sees the old file or the
        // new one, never half of one, which would be a parse error.
        $temporary = CacheFile::createTemporary(dirname($file));
        if ($temporary === false) {
            throw ContainerException::compiledCacheNotWritable($file);
        }

        if (@file_put_contents($temporary, $code) === false) {
            @unlink($temporary);

            throw ContainerException::compiledCacheNotWritable($file);
        }

        $destination = dirname($temporary) . DIRECTORY_SEPARATOR . basename($file);
        if (!CacheFile::preservePermissions($temporary, $destination) || !@rename($temporary, $destination)) {
            @unlink($temporary);

            throw ContainerException::compiledCacheNotWritable($file);
        }
    }

    /**
     * The one place that knows the file's shape, so plans and generated
     * factories cannot drift into two formats read by one reader.
     *
     * @param array<class-string, SourceStamp|null> $stamps
     * @param string $key the section the entries belong to
     * @param list<string> $entries already-rendered `'Class' => …,` lines
     */
    public static function envelope(?string $buildStamp, array $stamps, string $key, array $entries, string $setup = ''): string
    {
        $stampLines = [];

        foreach ($stamps as $class => $stamp) {
            $stampLines[] = '        ' . var_export($class, true) . ' => ' . self::renderStamp($stamp) . ',';
        }

        return "<?php\n\ndeclare(strict_types=1);\n\n" . $setup . "return [\n"
            . "    'format' => " . self::FORMAT . ",\n"
            . "    'build' => " . var_export($buildStamp, true) . ",\n"
            . "    'stamps' => [\n"
            . implode("\n", $stampLines)
            . "\n    ],\n"
            . "    '" . $key . "' => [\n"
            . implode("\n", $entries)
            . "\n    ],\n];\n";
    }

    private static function isExportable(mixed $value): bool
    {
        if (is_object($value)) {
            return $value instanceof UnitEnum;
        }

        if (is_array($value)) {
            /** @var mixed $entry */
            foreach ($value as $entry) {
                if (!self::isExportable($entry)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @throws ContainerException
     *
     * @return array<class-string, mixed>
     */
    private static function entries(string $file, string $key, ?string $buildStamp): array
    {
        $envelope = self::load($file);

        /** @var mixed $entries */
        $entries = $envelope[$key] ?? null;

        if (!is_array($entries)) {
            throw ContainerException::compiledCacheInvalid($file);
        }

        /** @var array<class-string, mixed> $entries */
        $verdict = self::buildVerdict($envelope['build'] ?? null, $buildStamp);

        if ($verdict === false) {
            return [];
        }

        if ($verdict === true) {
            return $entries;
        }

        /** @var array<class-string, SourceStamp|null> $stamps */
        $stamps = is_array($envelope['stamps'] ?? null) ? $envelope['stamps'] : [];

        $checked = [];
        foreach ($entries as $class => $_) {
            if (!CacheStamp::isCurrent($stamps[$class] ?? null, $checked)) {
                unset($entries[$class]);
            }
        }

        return $entries;
    }

    /**
     * @throws ContainerException
     *
     * @return array<string, mixed>
     */
    private static function load(string $file): array
    {
        if (!is_file($file)) {
            throw ContainerException::compiledCacheNotReadable($file);
        }

        $trusted = CacheFile::trustedPath($file);
        if ($trusted === null) {
            throw ContainerException::compiledCacheUntrusted($file);
        }

        /**
         * @psalm-suppress UnresolvableInclude
         *
         * @var mixed $envelope
         */
        $envelope = include $trusted;

        if ($envelope === false && !is_readable($trusted)) {
            throw ContainerException::compiledCacheNotReadable($file);
        }

        if (!is_array($envelope)) {
            throw ContainerException::compiledCacheInvalid($file);
        }

        /** @var array<string, mixed> $envelope */
        if (($envelope['format'] ?? null) !== self::FORMAT) {
            throw ContainerException::compiledCacheFormatMismatch($file);
        }

        return $envelope;
    }

    /**
     * @param mixed $stamped what the file was written with
     * @param string|null $buildStamp what the caller claims to be running
     *
     * @return bool|null true to take the whole file, false to discard it,
     *   null to judge each entry on its own stamp
     */
    private static function buildVerdict(mixed $stamped, ?string $buildStamp): ?bool
    {
        if ($buildStamp === null || !is_string($stamped)) {
            return null;
        }

        return $stamped === $buildStamp;
    }

    private static function isWritable(string $file): bool
    {
        if (is_dir($file)) {
            return false;
        }

        if (is_file($file)) {
            return is_writable($file);
        }

        $directory = dirname($file);

        return is_dir($directory) && is_writable($directory);
    }

    /**
     * @param SourceStamp|null $stamp
     */
    private static function renderStamp(?array $stamp): string
    {
        if ($stamp === null) {
            return 'null';
        }

        $files = [];
        foreach ($stamp as [$file, $mtime, $size, $hash]) {
            $files[] = '[' . var_export($file, true) . ', ' . $mtime . ', ' . $size . ', ' . var_export($hash, true) . ']';
        }

        return '[' . implode(', ', $files) . ']';
    }
}
