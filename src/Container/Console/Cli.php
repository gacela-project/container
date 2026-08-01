<?php

declare(strict_types=1);

namespace Gacela\Container\Console;

use Gacela\Container\ClassSource;
use Gacela\Container\CompilationReport;
use Gacela\Container\Container;
use Throwable;

use function array_slice;
use function count;
use function dirname;
use function in_array;
use function is_dir;
use function is_file;
use function is_string;
use function sprintf;
use function str_starts_with;
use function substr;

/**
 * `vendor/bin/gacela-container`.
 *
 * The README leads with compilation and the biggest measured win in the library
 * is behind it, but using it meant hand-writing a bootstrap script that built a
 * container, enumerated classes and called the write methods with a build
 * stamp. That script is the same in every application, and getting it wrong is
 * silent: a missing or mismatched stamp degrades to "cache discarded", which
 * costs performance and reports nothing.
 *
 * No console framework. The argv parsing here is thirty lines, and pulling
 * symfony/console into a container whose selling point is its footprint would
 * cost more than the whole feature is worth. `psr/container` stays the only
 * runtime dependency.
 *
 * @internal
 * Not covered by backward compatibility: the CLI is a build tool, not part of
 * the library's API surface, and may change or disappear in any release
 */
final class Cli
{
    public const EXIT_OK = 0;

    public const EXIT_FAILURE = 1;

    private const DEFAULT_CONFIG = 'gacela-container.php';

    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    /**
     * @param resource|null $out
     * @param resource|null $err
     */
    public function __construct($out = null, $err = null)
    {
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
    }

    /**
     * @param list<string> $argv the whole argv, script name included
     */
    public static function main(array $argv): int
    {
        return (new self())->run($argv);
    }

    /**
     * @param list<string> $argv the whole argv, script name included
     */
    public function run(array $argv): int
    {
        $arguments = array_slice($argv, 1);
        $command = $arguments[0] ?? 'help';

        try {
            $options = $this->parseOptions(array_slice($arguments, 1));

            return match ($command) {
                'compile' => $this->compile($options),
                'report' => $this->report($options),
                'validate' => $this->validate($options),
                'help', '--help', '-h' => $this->help(),
                '--version', '-V' => $this->version(),
                default => throw CliException::unknownCommand($command),
            };
        } catch (Throwable $exception) {
            // Everything the user can act on arrives as a CliException; a broken
            // container factory or an unwritable path arrives as anything at
            // all. Both end the same way, because for a build tool the message
            // is the useful part and the trace is not.
            return $this->fail($exception->getMessage());
        }
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function compile(array $options): int
    {
        $config = $this->configFrom($options);
        $container = $config->container();
        $source = $this->sourceFrom($options, $config);

        $plans = $this->stringOption($options, 'plans') ?? $config->plans;
        $factories = $this->stringOption($options, 'factories') ?? $config->factories;
        $stamp = $this->stringOption($options, 'stamp') ?? $config->stamp;

        if ($plans === null && $factories === null) {
            throw CliException::nothingToWrite();
        }

        $classNames = $source->classNames();
        $this->reportDiscovery($classNames, count($container->compile($source)));

        if ($plans !== null) {
            $this->ensureDirectory($plans);
            $container->writeCompiledCache($source, $plans, $stamp);
            $this->write($this->out, sprintf("Wrote plans to %s\n", $plans));
        }

        if ($factories !== null) {
            $this->ensureDirectory($factories);
            $compiled = $container->writeCompiledFactories($source, $factories, $stamp);
            $this->write($this->out, sprintf(
                "Wrote %d factory/factories to %s (%d refused).\n",
                count($compiled),
                $factories,
                count($classNames) - count($compiled),
            ));
        }

        if ($stamp === null) {
            $this->write($this->out, "No --stamp given: every entry is validated by its file's mtime instead.\n");
        }

        return self::EXIT_OK;
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function report(array $options): int
    {
        $config = $this->configFrom($options);
        $container = $config->container();
        $source = $this->sourceFrom($options, $config);

        $classNames = $source->classNames();
        $report = $container->compileReport($source);

        $this->reportDiscovery($classNames, count($report->compiled()) + count($report->skipped()));
        $this->printReport($report);

        $skipped = count($report->skipped());

        if ($skipped > 0 && ($options['strict'] ?? false) === true) {
            throw CliException::strictFailure($skipped);
        }

        return self::EXIT_OK;
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function validate(array $options): int
    {
        $config = $this->configFrom($options);
        $container = $config->container();
        $source = $this->sourceFrom($options, $config);

        $classNames = $source->classNames();
        $report = $container->validate($source);

        $this->reportDiscovery($classNames, count($report->checked()));
        $this->write($this->out, $report->render() . "\n");

        // Unlike report, this fails by default: an unresolvable service is a
        // broken build, not a missed optimisation.
        return $report->isValid() ? self::EXIT_OK : self::EXIT_FAILURE;
    }

    /**
     * Say how many classes discovery found, and call it out when none of them
     * could be loaded. Every command opens with this, which is why it is one
     * call rather than the same two lines three times.
     *
     * Discovery reads declarations off disk; planning needs the classes
     * *loaded*. When every one of them failed to load, the run reports a
     * cheerful zero and has in fact done nothing — the exact silent failure this
     * command exists to remove, so it is called out.
     *
     * @param list<class-string> $classNames
     * @param int $planned how many of them the command was able to act on
     */
    private function reportDiscovery(array $classNames, int $planned): void
    {
        $this->write($this->out, sprintf("Discovered %d class(es).\n", count($classNames)));

        if ($classNames === [] || $planned > 0) {
            return;
        }

        $count = count($classNames);

        $this->write($this->err, <<<TXT
            Warning: none of the {$count} discovered class(es) could be loaded, so nothing was
            compiled. The autoloader that maps them is usually missing — the config file
            is the place to require it.

            TXT);
    }

    private function printReport(CompilationReport $report): void
    {
        $compiled = $report->compiled();
        $explanations = $report->explanations();

        $this->write($this->out, sprintf(
            "%d compiled, %d refused.\n",
            count($compiled),
            count($explanations),
        ));

        if ($compiled !== []) {
            $this->write($this->out, "\nCompiled:\n");
            foreach ($compiled as $class) {
                $this->write($this->out, "  {$class}\n");
            }
        }

        if ($explanations === []) {
            return;
        }

        // Driven off reasons() rather than explanations(): the two are written
        // together and keyed alike, and this way the reason is not nullable and
        // there is no unreachable "unknown" branch to defend.
        $this->write($this->out, "\nRefused:\n");
        foreach ($report->reasons() as $class => $reason) {
            $why = $explanations[$class] ?? '';
            $this->write($this->out, "  {$class}\n    [{$reason->value}] {$why}\n");
        }
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function configFrom(array $options): CliConfig
    {
        $file = $this->stringOption($options, 'config') ?? self::DEFAULT_CONFIG;

        if (!is_file($file)) {
            throw CliException::configNotFound($file);
        }

        /**
         * The path is the user's, so it cannot be resolved statically.
         *
         * @psalm-suppress UnresolvableInclude
         *
         * @var mixed $returned
         */
        $returned = require $file;

        return CliConfig::fromFileReturn($returned, $file);
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function sourceFrom(array $options, CliConfig $config): ClassSource
    {
        $source = $this->stringOption($options, 'source');

        if ($source === null) {
            return $config->source ?? throw CliException::noSource();
        }

        if ($source === 'classmap') {
            return ClassSource::fromComposerClassmap();
        }

        if (is_dir($source)) {
            return ClassSource::fromDirectory($source);
        }

        // A path that is neither of those is a classmap file, which is what
        // makes `--source=vendor/composer/autoload_classmap.php` work.
        return ClassSource::fromComposerClassmap($source);
    }

    /**
     * `--name=value`, `--name value` is deliberately not supported, and `--flag`
     * is a bool. Anything else is rejected rather than ignored: silently
     * dropping a misspelled `--factories` would write no factories and say
     * nothing, which is the failure mode this command exists to remove.
     *
     * @param list<string> $arguments
     *
     * @return array<string, string|bool>
     */
    private function parseOptions(array $arguments): array
    {
        $options = [];

        foreach ($arguments as $argument) {
            if (!str_starts_with($argument, '--')) {
                throw CliException::unknownOption($argument);
            }

            $argument = substr($argument, 2);
            $equals = strpos($argument, '=');

            if ($equals === false) {
                $options[$argument] = true;
                continue;
            }

            $options[substr($argument, 0, $equals)] = substr($argument, $equals + 1);
        }

        foreach (array_keys($options) as $name) {
            if (!in_array($name, ['plans', 'factories', 'stamp', 'source', 'config', 'strict'], true)) {
                throw CliException::unknownOption('--' . $name);
            }
        }

        return $options;
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function stringOption(array $options, string $name): ?string
    {
        $value = $options[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function ensureDirectory(string $file): void
    {
        $directory = dirname($file);

        if (!is_dir($directory)) {
            @mkdir($directory, 0o775, true);
        }
    }

    private function help(): int
    {
        $this->write($this->out, <<<TXT
        gacela-container — compile constructor plans and factories ahead of time.

        USAGE
          gacela-container compile [options]
          gacela-container report [options]

        COMMANDS
          compile    Write plans and/or factories, and say what was written.
          report     Print what would be compiled and why the rest is refused.
          validate   Prove the classes resolve, without resolving them. Exits
                     non-zero on the first problem, so a deploy fails instead
                     of a request.

        OPTIONS
          --plans=FILE       Where to write constructor plans.
          --factories=FILE   Where to write generated `new` expressions.
          --stamp=VALUE      Build id — a commit sha, a deploy id. Pass the same
                             value to loadCompiledCache()/loadCompiledFactories()
                             and the whole file is validated in one comparison
                             instead of one stat per class.
          --source=WHAT      'classmap' for Composer's optimized classmap, a
                             directory to scan, or a path to a classmap file.
          --config=FILE      Defaults to ./gacela-container.php.
          --strict           report only: exit non-zero if anything was refused.
                             (validate always exits non-zero on a problem.)

        CONFIGURATION
          The CLI builds *your* container, because a file generated against no
          bindings would generate a `new` for a class your application binds.
          gacela-container.php returns it, plus any defaults:

            <?php
            return [
                'container' => static fn () => require __DIR__ . '/config/container.php',
                'source'    => \\Gacela\\Container\\ClassSource::fromDirectory(__DIR__ . '/src'),
                'plans'     => 'var/container-plans.php',
                'factories' => 'var/container-factories.php',
            ];

        TXT);

        return self::EXIT_OK;
    }

    private function version(): int
    {
        $this->write($this->out, "gacela-container (gacela-project/container)\n");

        return self::EXIT_OK;
    }

    private function fail(string $message): int
    {
        $this->write($this->err, $message . "\n");

        return self::EXIT_FAILURE;
    }

    /**
     * @param resource $stream
     */
    private function write($stream, string $text): void
    {
        fwrite($stream, $text);
    }
}
