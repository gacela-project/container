<?php

declare(strict_types=1);

namespace Gacela\Container\Exception;

use Gacela\Container\FuzzyMatcher;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

use function sprintf;

/**
 * @api
 */
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

        $block = FuzzyMatcher::renderSuggestions($suggestions);

        if ($block !== '') {
            $message .= $block . "\n";
        }

        $message .= 'You might find some help here: https://gacela-project.com/docs/bootstrap/#bindings';

        return new self($message);
    }

    public static function unresolvableId(string $id): self
    {
        return new self(sprintf('Could not resolve a non-null instance for "%s".', $id));
    }
}
