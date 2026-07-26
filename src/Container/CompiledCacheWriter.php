<?php

declare(strict_types=1);

namespace Gacela\Container;

use Throwable;

use function file_put_contents;
use function implode;
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

        file_put_contents($file, self::render($entries));
    }

    /**
     * @return CompiledPlans
     */
    public static function read(string $file): array
    {
        /**
         * @psalm-suppress UnresolvableInclude
         *
         * @var CompiledPlans $plans
         */
        $plans = require $file;

        return $plans;
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
