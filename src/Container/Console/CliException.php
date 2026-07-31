<?php

declare(strict_types=1);

namespace Gacela\Container\Console;

use RuntimeException;

/**
 * Anything the CLI can tell the user to fix.
 *
 * Separate from ContainerException because none of it is a container problem:
 * these are a missing config file, an unknown flag, a directory that is not
 * writable. The CLI prints the message and exits non-zero rather than showing a
 * stack trace.
 *
 * @internal
 * Not covered by backward compatibility: this class is an implementation
 * detail of the CLI and may change or disappear in any release
 */
final class CliException extends RuntimeException
{
    public static function unknownCommand(string $command): self
    {
        return new self("Unknown command '{$command}'. Try 'gacela-container help'.");
    }

    public static function unknownOption(string $option): self
    {
        return new self("Unknown option '{$option}'. Try 'gacela-container help'.");
    }

    public static function configNotFound(string $file): self
    {
        return new self(
            "No configuration found at '{$file}'.\n\n"
            . "The CLI has to build *your* container to see your bindings, so it cannot\n"
            . "run without one. Create it:\n\n"
            . "  <?php // gacela-container.php\n"
            . "  return [\n"
            . "      'container' => static fn () => require __DIR__ . '/config/container.php',\n"
            . "      'source'    => \\Gacela\\Container\\ClassSource::fromDirectory(__DIR__ . '/src'),\n"
            . "      'plans'     => 'var/container-plans.php',\n"
            . "      'factories' => 'var/container-factories.php',\n"
            . "  ];\n\n"
            . 'Or point at one with --config=path/to/file.php.',
        );
    }

    public static function configInvalid(string $file, string $reason): self
    {
        return new self("The configuration '{$file}' cannot be used: {$reason}.");
    }

    public static function nothingToWrite(): self
    {
        return new self(
            "Nothing to write: pass --plans=FILE, --factories=FILE, or both\n"
            . '(or set them in the configuration).',
        );
    }

    public static function noSource(): self
    {
        return new self(
            "No classes to compile. Pass --source=classmap or --source=DIRECTORY,\n"
            . "or set 'source' in the configuration.",
        );
    }

    public static function strictFailure(int $skipped): self
    {
        return new self("--strict: {$skipped} class(es) were not compiled.");
    }
}
