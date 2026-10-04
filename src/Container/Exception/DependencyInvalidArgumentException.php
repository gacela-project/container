<?php

declare(strict_types=1);

namespace Gacela\Container\Exception;

use Gacela\Container\FuzzyMatcher;
use InvalidArgumentException;
use Psr\Container\ContainerExceptionInterface;

use function array_map;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function implode;
use function in_array;
use function ltrim;
use function strtolower;

/**
 * @api
 */
final class DependencyInvalidArgumentException extends InvalidArgumentException implements ContainerExceptionInterface
{
    private const BUILT_IN_TYPES = ['string', 'int', 'float', 'bool', 'array', 'iterable', 'mixed', 'callable', 'object', 'false', 'true', 'null'];

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

        // A name that is no built-in type is a class or interface that does not
        // exist: suggesting a default value would be advice no PHP accepts.
        if (!in_array(strtolower(ltrim($parameter, '?')), self::BUILT_IN_TYPES, true)) {
            $message = <<<TXT
Unable to resolve parameter of type '{$parameter}' in '{$className}'.{$chainInfo}
No class or interface '{$parameter}' exists.

Check the type's import and spelling, and that the autoloader can find it.
TXT;
            return new self($message);
        }

        $message = <<<TXT
Unable to resolve parameter of type '{$parameter}' in '{$className}'.{$chainInfo}
Scalar types (string, int, float, bool, array) cannot be auto-resolved.

Provide a default value for the parameter:
  public function __construct({$parameter} \$param = 'default') { ... }
TXT;
        return new self($message);
    }

    /**
     * @param list<string> $resolutionChain
     */
    public static function noPropertyTypeFor(string $className, string $property, array $resolutionChain = []): self
    {
        $chainInfo = self::formatResolutionChain($resolutionChain);

        $message = <<<TXT
No type hint found for property '{$className}::\${$property}'.{$chainInfo}
A #[Inject] property must declare the type to resolve.

Add a type to the property, for example:
  #[Inject]
  private YourClass \${$property};
TXT;
        return new self($message);
    }

    /**
     * @param list<string> $resolutionChain
     */
    public static function unableToResolveProperty(
        string $className,
        string $property,
        string $type,
        array $resolutionChain = [],
    ): self {
        $chainInfo = self::formatResolutionChain($resolutionChain);

        $message = <<<TXT
Unable to resolve #[Inject] property '{$className}::\${$property}' of type '{$type}'.{$chainInfo}
Scalar types (string, int, float, bool, array) cannot be auto-resolved.

Pass it through the constructor instead, so it can be given a value:
  public function __construct(private {$type} \${$property}) { ... }
TXT;
        return new self($message);
    }

    /**
     * @param list<string> $resolutionChain
     */
    public static function readonlyPropertyInjection(
        string $className,
        string $property,
        array $resolutionChain = [],
    ): self {
        $chainInfo = self::formatResolutionChain($resolutionChain);

        $message = <<<TXT
The property '{$className}::\${$property}' is readonly and cannot be injected.{$chainInfo}
A readonly property may only be written from inside the declaring class, so the
container cannot assign it after construction.

Promote it to a constructor parameter instead, which keeps it readonly:
  public function __construct(private readonly YourClass \${$property}) { ... }
TXT;
        return new self($message);
    }

    /**
     * @param list<string> $chainInfo
     */
    public static function staticMethodInjection(
        string $className,
        string $method,
        array $chainInfo = [],
    ): self {
        $chain = self::formatResolutionChain($chainInfo);

        $message = <<<TXT
The method '{$className}::{$method}()' is static and cannot be injected.{$chain}
Injection calls the method on an instance, and a static method has none.

Drop the #[Inject], or make the method an instance method:
  #[Inject]
  public function {$method}(YourClass \$dependency): void { ... }
TXT;
        return new self($message);
    }

    /**
     * @param list<string> $chainInfo
     */
    public static function nonPublicMethodInjection(
        string $className,
        string $method,
        array $chainInfo = [],
    ): self {
        $chain = self::formatResolutionChain($chainInfo);

        $message = <<<TXT
The method '{$className}::{$method}()' is not public and cannot be injected.{$chain}
The container calls the method from outside the class, so it has to be public.

Make it public, or move the dependency to the constructor:
  #[Inject]
  public function {$method}(YourClass \$dependency): void { ... }
TXT;
        return new self($message);
    }

    /**
     * @param list<string> $unknown the keys passed to make() that no constructor parameter has
     * @param list<string> $known the constructor's parameter names
     */
    public static function unknownParameters(string $className, array $unknown, array $known): self
    {
        $quoted = implode(', ', array_map(static fn (string $name): string => "'{$name}'", $unknown));
        $takes = $known === []
            ? 'It takes no constructor parameters.'
            : 'Its constructor takes: ' . implode(', ', array_map(static fn (string $name): string => '$' . $name, $known));

        $suggestions = [];
        foreach ($unknown as $name) {
            $suggestions = array_merge($suggestions, FuzzyMatcher::findSimilar($name, $known));
        }

        $message = <<<TXT
Unknown parameter(s) {$quoted} passed to make() for '{$className}'.
{$takes}
TXT;
        $message .= FuzzyMatcher::renderSuggestions(array_slice(array_values(array_unique($suggestions)), 0, 3));

        return new self($message);
    }

    /**
     * @param list<string> $chain
     */
    private static function formatResolutionChain(array $chain): string
    {
        if ($chain === []) {
            return '';
        }

        $formatted = implode(' -> ', $chain);
        return "\nResolution chain: {$formatted}";
    }
}
