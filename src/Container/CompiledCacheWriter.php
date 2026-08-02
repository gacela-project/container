<?php

declare(strict_types=1);

namespace Gacela\Container;

use Gacela\Container\Exception\ContainerException;
use Throwable;

use function dirname;
use function file_put_contents;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function is_readable;
use function is_string;
use function is_writable;
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
 * stale plan is worse than no plan. It is still a plain `return [...]`, so
 * opcache maps it exactly as before.
 *
 * @psalm-import-type CompiledPlans from PlanRegistry
 * @psalm-import-type FactoriesMap from ContainerInterface
 * @psalm-import-type FileStamp from CacheStamp
 *
 * @internal
 * Not covered by backward compatibility: this class is an implementation
 * detail of Container and may change or disappear in any release
 */
final class CompiledCacheWriter
{
    /**
     * Bumped whenever the envelope changes shape. A file written by another
     * version is refused outright rather than half-understood.
     */
    public const FORMAT = 1;

    /**
     * Classes whose default values cannot be statically exported are skipped
     * and fall back to reflection at runtime, so correctness never depends on
     * the cache being complete.
     *
     * @param CompiledPlans $plans
     * @param string|null $buildStamp identifies the build this file belongs to;
     *   see read() for what it buys
     */
    public static function write(array $plans, string $file, ?string $buildStamp = null): void
    {
        $entries = [];
        $stamps = [];

        foreach ($plans as $class => $plan) {
            try {
                $exportedPlan = var_export($plan, true);
            } catch (Throwable) {
                continue;
            }

            $entries[] = '        ' . var_export($class, true) . ' => ' . $exportedPlan . ',';
            $stamps[$class] = CacheStamp::of($class);
        }

        self::put($file, self::envelope($buildStamp, $stamps, 'plans', $entries));
    }

    /**
     * Entries whose declaring file has changed since the cache was written are
     * dropped, so a stale entry behaves exactly like a missing one and the
     * class falls back to reflection.
     *
     * $buildStamp is the escape hatch for large maps, where one stat per class
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

        if (@file_put_contents($file, $code) === false) {
            throw ContainerException::compiledCacheNotWritable($file);
        }
    }

    /**
     * The one place that knows the file's shape, so plans and generated
     * factories cannot drift into two formats read by one reader.
     *
     * @param array<class-string, FileStamp|null> $stamps
     * @param string $key the section the entries belong to
     * @param list<string> $entries already-rendered `'Class' => …,` lines
     */
    public static function envelope(?string $buildStamp, array $stamps, string $key, array $entries): string
    {
        $stampLines = [];

        foreach ($stamps as $class => $stamp) {
            $stampLines[] = '        ' . var_export($class, true) . ' => ' . self::renderStamp($stamp) . ',';
        }

        return "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n"
            . "    'format' => " . self::FORMAT . ",\n"
            . "    'build' => " . var_export($buildStamp, true) . ",\n"
            . "    'stamps' => [\n"
            . implode("\n", $stampLines)
            . "\n    ],\n"
            . "    '" . $key . "' => [\n"
            . implode("\n", $entries)
            . "\n    ],\n];\n";
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

        /** @var array<class-string, FileStamp|null> $stamps */
        $stamps = is_array($envelope['stamps'] ?? null) ? $envelope['stamps'] : [];

        foreach ($entries as $class => $_) {
            if (!CacheStamp::isCurrent($stamps[$class] ?? null)) {
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
        if (!is_file($file) || !is_readable($file)) {
            throw ContainerException::compiledCacheNotReadable($file);
        }

        /**
         * @psalm-suppress UnresolvableInclude
         *
         * @var mixed $envelope
         */
        $envelope = require $file;

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
     * Rendered by hand rather than var_export()ed: a stamp is three scalars,
     * and one line per class keeps the file readable when a build inspects it.
     *
     * @param FileStamp|null $stamp
     */
    private static function renderStamp(?array $stamp): string
    {
        if ($stamp === null) {
            return 'null';
        }

        [$file, $mtime, $size] = $stamp;

        return '[' . var_export($file, true) . ', ' . $mtime . ', ' . $size . ']';
    }
}
