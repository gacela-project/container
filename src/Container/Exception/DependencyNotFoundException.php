<?php

declare(strict_types=1);

namespace Gacela\Container\Exception;

use Gacela\Container\FuzzyMatcher;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

use function implode;
use function interface_exists;
use function sprintf;
use function strrpos;
use function substr;

/**
 * @api
 */
final class DependencyNotFoundException extends RuntimeException implements NotFoundExceptionInterface
{
    /**
     * @param list<string> $suggestions
     * @param string|null $neededBy what asked for it, for example "parameter $mailer of App\\Notifier::__construct()"
     * @param list<string> $resolutionChain
     */
    public static function mapNotFoundForClassName(string $className, array $suggestions = [], ?string $neededBy = null, array $resolutionChain = []): self
    {
        $message = sprintf('Nothing is bound to "%s", and it cannot be built: it is an interface or an abstract class.', $className);

        if ($neededBy !== null) {
            $message .= "\nNeeded by " . $neededBy . '.';
        }

        if ($resolutionChain !== []) {
            $message .= "\nResolution chain: " . implode(' -> ', $resolutionChain);
        }

        $message .= sprintf("\n\nBind it to a concrete class:\n  \$container->bind(%s::class, YourImplementation::class);\n", self::shortName($className));

        $message .= FuzzyMatcher::renderSuggestions($suggestions);

        return new self($message . "\nSee https://github.com/gacela-project/container/blob/main/docs/bindings.md");
    }

    public static function unresolvableId(string $id): self
    {
        // get() already refuses an abstract class, so only an unbound interface
        // comes back as null.
        if (interface_exists($id)) {
            return self::mapNotFoundForClassName($id);
        }

        return new self(sprintf('Could not resolve a non-null instance for "%s".', $id));
    }

    private static function shortName(string $className): string
    {
        $position = strrpos($className, '\\');

        return $position === false ? $className : substr($className, $position + 1);
    }
}
