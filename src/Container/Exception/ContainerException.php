<?php

declare(strict_types=1);

namespace Gacela\Container\Exception;

use Exception;
use Psr\Container\ContainerExceptionInterface;

final class ContainerException extends Exception implements ContainerExceptionInterface
{
    public static function instanceNotExtendable(): self
    {
        return new self('The passed instance is not extendable.');
    }

    public static function instanceFrozen(string $id): self
    {
        return new self("The instance '{$id}' is frozen and cannot be extendable.");
    }

    public static function instanceProtected(string $id): self
    {
        return new self("The instance '{$id}' is protected and cannot be extendable.");
    }
}
