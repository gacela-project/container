<?php

declare(strict_types=1);

namespace Gacela\Container\Exception;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

use function count;

final class DependencyNotFoundException extends RuntimeException implements NotFoundExceptionInterface
{
    /**
     * @param list<string> $suggestions
     */
    public static function mapNotFoundForClassName(string $className, array $suggestions = []): self
    {
        $message = <<<TXT
No concrete class was found that implements:
"{$className}"
Did you forget to bind this interface to a concrete class?

TXT;

        if (count($suggestions) > 0) {
            $message .= "\nDid you mean one of these?\n";
            foreach ($suggestions as $suggestion) {
                $message .= "  - {$suggestion}\n";
            }
            $message .= "\n";
        }

        $message .= 'You might find some help here: https://gacela-project.com/docs/bootstrap/#bindings';

        return new self($message);
    }
}
