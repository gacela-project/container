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
 * Messages are heredocs rather than concatenated lines, like ContainerException:
 * a `.` between two pieces of prose is a mutation with no test that can
 * meaningfully catch it, and one string literal has none.
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
        $message = <<<TXT
No configuration found at '{$file}'.

The CLI has to build *your* container to see your bindings, so it cannot
run without one. Create it:

  <?php // gacela-container.php
  return [
      'container' => static fn () => require __DIR__ . '/config/container.php',
      'source'    => \\Gacela\\Container\\ClassSource::fromDirectory(__DIR__ . '/src'),
      'plans'     => 'var/container-plans.php',
      'factories' => 'var/container-factories.php',
  ];

Or point at one with --config=path/to/file.php.
TXT;
        return new self($message);
    }

    public static function configInvalid(string $file, string $reason): self
    {
        return new self("The configuration '{$file}' cannot be used: {$reason}.");
    }

    public static function nothingToWrite(): self
    {
        $message = <<<TXT
Nothing to write: pass --plans=FILE, --factories=FILE, or both
(or set them in the configuration).
TXT;
        return new self($message);
    }

    public static function noSource(): self
    {
        $message = <<<TXT
No classes to compile. Pass --source=classmap or --source=DIRECTORY,
or set 'source' in the configuration.
TXT;
        return new self($message);
    }

    public static function strictFailure(int $skipped): self
    {
        return new self("--strict: {$skipped} class(es) were not compiled.");
    }
}
