<?php

declare(strict_types=1);

namespace Gacela\Container\Exception;

use Exception;
use Psr\Container\ContainerExceptionInterface;

final class ContainerException extends Exception implements ContainerExceptionInterface
{
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
