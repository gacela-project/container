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
use function is_writable;
use function var_export;

/**
 * Turns constructor plans into an opcache-friendly PHP file, and reads them
 * back.
 *
 * Kept off Container: generating code and touching the filesystem is a
 * build-time concern, not something a runtime container should carry.
 *
 * @psalm-import-type CompiledPlans from DependencyResolver
 *
 * @internal
 * Not covered by backward compatibility: this class is an implementation
 * detail of Container and may change or disappear in any release
 */
final class CompiledCacheWriter
{
    /**
     * Classes whose default values cannot be statically exported are skipped
     * and fall back to reflection at runtime, so correctness never depends on
     * the cache being complete.
     *
     * @param CompiledPlans $plans
     */
    public static function write(array $plans, string $file): void
    {
        $entries = [];

        foreach ($plans as $class => $plan) {
            try {
                $exportedPlan = var_export($plan, true);
            } catch (Throwable) {
                continue;
            }

            $entries[] = var_export($class, true) . ' => ' . $exportedPlan . ',';
        }

        self::put($file, self::render($entries));
    }

    /**
     * @throws ContainerException when the file is missing, unreadable, or does
     *                            not return an array
     *
     * @return CompiledPlans
     */
    public static function read(string $file): array
    {
        if (!is_file($file) || !is_readable($file)) {
            throw ContainerException::compiledCacheNotReadable($file);
        }

        /**
         * @psalm-suppress UnresolvableInclude
         *
         * @var mixed $plans
         */
        $plans = require $file;

        if (!is_array($plans)) {
            throw ContainerException::compiledCacheInvalid($file);
        }

        /** @var CompiledPlans $plans */
        return $plans;
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
     * @param list<string> $entries
     */
    private static function render(array $entries): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n"
            . implode("\n", $entries)
            . "\n];\n";
    }
}
