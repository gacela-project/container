<?php

declare(strict_types=1);

namespace Gacela\Container\Console;

use Gacela\Container\ClassSource;
use Gacela\Container\Container;

use function is_array;
use function is_callable;
use function is_string;

/**
 * What the CLI needs to know that the command line does not tell it.
 *
 * Chiefly the container itself. A compiled file generated against no bindings
 * is wrong in the dangerous direction — `ContainerCompiler` refuses a bound
 * class, so a container that has never seen your `bind()` calls would happily
 * generate a `new` for something the application binds, and the generated file
 * would then be installed into a container that binds it. So the config has to
 * hand over the *configured* container, which means naming a callable that
 * builds one; there is no way to infer it.
 *
 * A config file returns either that container, a callable producing one, or an
 * array with `container` plus whatever defaults should be shared between CI and
 * a developer's shell.
 *
 * @internal
 * Not covered by backward compatibility: this class is an implementation
 * detail of the CLI and may change or disappear in any release
 */
final class CliConfig
{
    /**
     * @param callable(): Container $containerFactory
     */
    private function __construct(
        private $containerFactory,
        public readonly ?ClassSource $source = null,
        public readonly ?string $plans = null,
        public readonly ?string $factories = null,
        public readonly ?string $stamp = null,
    ) {
    }

    /**
     * @param mixed $returned whatever the config file returned
     *
     * @throws CliException
     */
    public static function fromFileReturn(mixed $returned, string $file): self
    {
        if ($returned instanceof Container) {
            $container = $returned;

            return new self(static fn (): Container => $container);
        }

        if (is_callable($returned)) {
            return new self(self::assertBuildsContainer($returned, $file));
        }

        if (!is_array($returned)) {
            throw CliException::configInvalid($file, 'it returned neither a Container, a callable, nor an array');
        }

        /** @var mixed $factory */
        $factory = $returned['container'] ?? null;

        if ($factory instanceof Container) {
            $built = $factory;
            $factory = static fn (): Container => $built;
        } elseif (!is_callable($factory)) {
            throw CliException::configInvalid($file, "its 'container' key is not a Container or a callable returning one");
        }

        /** @var mixed $source */
        $source = $returned['source'] ?? null;

        if ($source !== null && !$source instanceof ClassSource) {
            throw CliException::configInvalid($file, "its 'source' key is not a ClassSource");
        }

        return new self(
            self::assertBuildsContainer($factory, $file),
            $source,
            self::stringOrNull($returned['plans'] ?? null),
            self::stringOrNull($returned['factories'] ?? null),
            self::stringOrNull($returned['stamp'] ?? null),
        );
    }

    public function container(): Container
    {
        return ($this->containerFactory)();
    }

    /**
     * The callable is checked when it runs rather than here — there is no way to
     * know what a callable returns without calling it, and calling it early
     * would build the application container before we know a command needs one.
     *
     * @return callable(): Container
     */
    private static function assertBuildsContainer(callable $factory, string $file): callable
    {
        return static function () use ($factory, $file): Container {
            /** @var mixed $container */
            $container = $factory();

            if (!$container instanceof Container) {
                throw CliException::configInvalid($file, 'its container factory did not return a Container');
            }

            return $container;
        };
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
