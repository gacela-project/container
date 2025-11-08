<?php

declare(strict_types=1);

namespace Gacela\Container\Exception;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

final class CircularDependencyException extends RuntimeException implements ContainerExceptionInterface
{
    /**
     * @param list<string> $dependencyChain
     */
    public static function create(array $dependencyChain): self
    {
        $chain = implode(' -> ', $dependencyChain);
        $message = <<<TXT
Circular dependency detected: {$chain}

This happens when classes depend on each other in a loop.
Consider using setter injection or the factory pattern to break the cycle.
TXT;
        return new self($message);
    }
}
