<?php

declare(strict_types=1);

namespace Gacela\Container\Exception;

use InvalidArgumentException;
use Psr\Container\ContainerExceptionInterface;

final class DependencyInvalidArgumentException extends InvalidArgumentException implements ContainerExceptionInterface
{
    /**
     * @param list<string> $resolutionChain
     */
    public static function noParameterTypeFor(string $parameter, array $resolutionChain = []): self
    {
        $chainInfo = self::formatResolutionChain($resolutionChain);

        $message = <<<TXT
No type hint found for parameter '\${$parameter}'.{$chainInfo}
Type hints are required for dependency injection to work properly.

Add a type hint to the parameter, for example:
  public function __construct(YourClass \${$parameter}) { ... }
TXT;
        return new self($message);
    }

    /**
     * @param list<string> $resolutionChain
     */
    public static function unableToResolve(string $parameter, string $className, array $resolutionChain = []): self
    {
        $chainInfo = self::formatResolutionChain($resolutionChain);

        $message = <<<TXT
Unable to resolve parameter of type '{$parameter}' in '{$className}'.{$chainInfo}
Scalar types (string, int, float, bool, array) cannot be auto-resolved.

Provide a default value for the parameter:
  public function __construct({$parameter} \$param = 'default') { ... }
TXT;
        return new self($message);
    }

    /**
     * @param list<string> $chain
     */
    private static function formatResolutionChain(array $chain): string
    {
        if (empty($chain)) {
            return '';
        }

        $formatted = implode(' -> ', $chain);
        return "\nResolution chain: {$formatted}";
    }
}
