<?php

declare(strict_types=1);

namespace Gacela\Resolver\Exception;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final class DependencyNotFoundException extends RuntimeException implements NotFoundExceptionInterface
{
    public static function mapNotFoundForClassName(string $className): self
    {
        $message = <<<TXT
Did you forget to map this interface to a concrete class? No concrete class was found that implements:
{$className}
TXT;
        return new self($message);
    }
}
