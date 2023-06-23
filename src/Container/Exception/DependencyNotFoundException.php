<?php

declare(strict_types=1);

namespace Gacela\Container\Exception;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final class DependencyNotFoundException extends RuntimeException implements NotFoundExceptionInterface
{
    public static function mapNotFoundForClassName(string $className): self
    {
        $message = <<<TXT
No concrete class was found that implements:
"{$className}"
Did you forget to bind this interface to a concrete class?

You might find some help here: https://gacela-project.com/docs/bootstrap/#bindings 
TXT;
        return new self($message);
    }
}
