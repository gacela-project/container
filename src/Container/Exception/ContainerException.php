<?php

declare(strict_types=1);

namespace Gacela\Container\Exception;

use Exception;
use Psr\Container\ContainerExceptionInterface;

/**
 * @api
 */
final class ContainerException extends Exception implements ContainerExceptionInterface
{
    public static function compiledCacheNotWritable(string $file): self
    {
        $message = <<<TXT
The compiled cache '{$file}' could not be written.

Check that the directory exists and is writable. Nothing was written, so any
existing cache is unchanged.
TXT;
        return new self($message);
    }

    public static function compiledCacheNotReadable(string $file): self
    {
        $message = <<<TXT
The compiled cache '{$file}' could not be read.

Generate it first with writeCompiledCache(), and regenerate it whenever a
compiled constructor changes.
TXT;
        return new self($message);
    }

    public static function compiledCacheInvalid(string $file): self
    {
        $message = <<<TXT
The compiled cache '{$file}' did not return an array.

The file is stale or corrupt. Delete it and regenerate it with
writeCompiledCache().
TXT;
        return new self($message);
    }

    public static function classNotInstantiable(string $class): self
    {
        $message = <<<TXT
'{$class}' cannot be instantiated.
Abstract classes, interfaces, enums and classes with a non-public constructor
cannot be built by the container.

Bind it to a concrete implementation:
  \$container->bind({$class}::class, YourConcreteClass::class);
TXT;
        return new self($message);
    }

    public static function instanceNotExtendable(): self
    {
        $message = <<<TXT
The passed instance is not extendable.
Only objects, arrays, and callables can be extended.

Ensure the service is one of these types before calling extend().
TXT;
        return new self($message);
    }

    public static function frozenInstanceExtend(string $id): self
    {
        $message = <<<TXT
The instance '{$id}' is frozen and cannot be extended.
Services become frozen after being accessed via get() to ensure consistency.

Extend the service before accessing it, or use remove() to unfreeze it first.
TXT;
        return new self($message);
    }

    public static function frozenInstanceOverride(string $id): self
    {
        $message = <<<TXT
The instance '{$id}' is frozen and cannot be overridden.
Services become frozen after being accessed via get() to ensure consistency.

Call remove('{$id}') before setting a new value, or avoid accessing it before replacement.
TXT;
        return new self($message);
    }

    public static function instanceProtected(string $id): self
    {
        $message = <<<TXT
The instance '{$id}' is protected and cannot be extended.
Protected closures are treated as values, not as service factories.

Remove the protect() wrapper if you need to extend this service.
TXT;
        return new self($message);
    }
}
