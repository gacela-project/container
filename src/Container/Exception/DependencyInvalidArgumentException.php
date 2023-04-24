<?php

declare(strict_types=1);

namespace Gacela\Container\Exception;

use InvalidArgumentException;
use Psr\Container\ContainerExceptionInterface;

final class DependencyInvalidArgumentException extends InvalidArgumentException implements ContainerExceptionInterface
{
    public static function noParameterTypeFor(string $parameter): self
    {
        return new self("No parameter type for '{$parameter}'");
    }

    public static function unableToResolve(string $parameter, string $className): self
    {
        return new self("Unable to resolve [{$parameter}] from {$className}");
    }
}
