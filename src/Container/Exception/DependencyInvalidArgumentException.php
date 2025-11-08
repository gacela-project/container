<?php

declare(strict_types=1);

namespace Gacela\Container\Exception;

use InvalidArgumentException;
use Psr\Container\ContainerExceptionInterface;

final class DependencyInvalidArgumentException extends InvalidArgumentException implements ContainerExceptionInterface
{
    public static function noParameterTypeFor(string $parameter): self
    {
        $message = <<<TXT
No type hint found for parameter '\${$parameter}'.
Type hints are required for dependency injection to work properly.

Add a type hint to the parameter, for example:
  public function __construct(YourClass \${$parameter}) { ... }
TXT;
        return new self($message);
    }

    public static function unableToResolve(string $parameter, string $className): self
    {
        $message = <<<TXT
Unable to resolve parameter of type '{$parameter}' in '{$className}'.
Scalar types (string, int, float, bool, array) cannot be auto-resolved.

Provide a default value for the parameter:
  public function __construct({$parameter} \$param = 'default') { ... }
TXT;
        return new self($message);
    }
}
