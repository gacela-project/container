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

    public static function frozenInstanceExtend(string $id): self
    {
        return new self("The instance '{$id}' is frozen and cannot be extended.");
    }

    public static function frozenInstanceOverride(string $id): self
    {
        return new self("The instance '{$id}' is frozen and cannot be override.");
    }

    public static function instanceProtected(string $id): self
    {
        return new self("The instance '{$id}' is protected and cannot be extended.");
    }
}
