<?php

declare(strict_types=1);

namespace Gacela\Container\Exception;

use Exception;
use Gacela\Container\FuzzyMatcher;
use Psr\Container\ContainerExceptionInterface;

/**
 * @api
 */
final class ContainerException extends Exception implements ContainerExceptionInterface
{
    public static function compiledCacheNotWritable(string $file): self
    {
        $message = <<<TXT
The compiled cache '{$file}' could not be written.

Check that the directory exists and is writable. Nothing was written, so any
existing cache is unchanged.
TXT;
        return new self($message);
    }

    public static function compiledCacheNotReadable(string $file): self
    {
        $message = <<<TXT
The compiled cache '{$file}' could not be read.

Generate it first with writeCompiledCache(), and regenerate it whenever a
compiled constructor changes.
TXT;
        return new self($message);
    }

    public static function compiledCacheInvalid(string $file): self
    {
        $message = <<<TXT
The compiled cache '{$file}' did not return an array.

The file is stale or corrupt. Delete it and regenerate it with
writeCompiledCache().
TXT;
        return new self($message);
    }

    /**
     * A router asking for a handler that was never registered is a
     * misconfiguration, and the keys it *could* have asked for are the fastest
     * way to see which half is wrong — the registration or the lookup.
     *
     * @param list<string> $knownKeys
     */
    public static function unknownTagKey(string $tag, string $key, array $knownKeys): self
    {
        $message = "The tag '{$tag}' has no entry keyed '{$key}'.\n";

        if ($knownKeys === []) {
            $message .= <<<TXT

Nothing is registered under that tag with a key. Register one with a map:
  \$container->tag(['{$key}' => YourHandler::class], '{$tag}');
TXT;

            return new self($message);
        }

        $message .= FuzzyMatcher::renderSuggestions(FuzzyMatcher::findSimilar($key, $knownKeys));

        $message .= "\nKeys registered under '{$tag}':\n";
        foreach ($knownKeys as $knownKey) {
            $message .= "  - {$knownKey}\n";
        }

        return new self($message);
    }

    public static function compiledCacheFormatMismatch(string $file): self
    {
        $message = <<<TXT
The compiled cache '{$file}' was written in a format this version does not read.

The file is a build artifact tied to the exact version that produced it.
Regenerate it with writeCompiledCache() or writeCompiledFactories().
TXT;
        return new self($message);
    }

    public static function classNotInstantiable(string $class): self
    {
        $message = <<<TXT
'{$class}' cannot be instantiated.
Abstract classes, interfaces, enums and classes with a non-public constructor
cannot be built by the container.

Bind it to a concrete implementation:
  \$container->bind({$class}::class, YourConcreteClass::class);
TXT;
        return new self($message);
    }

    public static function contextualNullForType(string $abstract): self
    {
        $message = <<<TXT
A contextual binding for the type '{$abstract}' cannot be null.

Not binding it at all already means "nothing is bound", so this could only be a
mistake. Did you mean to bind a parameter by name?

  ->needs('\$parameterName')->give(null)

Or to make the dependency optional on the class itself?

  public function __construct(private ?{$abstract} \$dep = null) { ... }
TXT;
        return new self($message);
    }

    public static function classmapNotFound(?string $file): self
    {
        $where = $file === null
            ? 'No vendor/composer/autoload_classmap.php was found above this installation.'
            : "The classmap '{$file}' could not be read as a class => file array.";

        $message = <<<TXT
{$where}

ClassSource::fromComposerClassmap() reads the map Composer generates. It is only
written for an optimized autoloader, so run:
  composer dump-autoload --optimize

Or point at the file explicitly with fromComposerClassmap('/path/to/map.php'),
or scan the source tree instead with ClassSource::fromDirectory('src/').
TXT;
        return new self($message);
    }

    public static function directoryNotFound(string $directory): self
    {
        $message = <<<TXT
The directory '{$directory}' does not exist.

ClassSource::fromDirectory() takes directories to scan for class declarations.
TXT;
        return new self($message);
    }

    public static function definitionFileUnreadable(string $file): self
    {
        $message = <<<TXT
The definitions file '{$file}' could not be read.

Check the path and that the file is readable by the running user.
TXT;
        return new self($message);
    }

    public static function definitionFileUnsupported(string $file): self
    {
        $message = <<<TXT
The definitions file '{$file}' has no supported extension.

loadFile() reads '.php' files returning an array, '.json' files, and '.yaml' /
'.yml' when a YAML parser is installed. Anything else — XML — is a userland
concern: parse it yourself and hand the array to load().
TXT;
        return new self($message);
    }

    public static function yamlParserMissing(string $file): self
    {
        $message = <<<TXT
No YAML parser is installed, so '{$file}' cannot be read.

YAML support is optional on purpose: psr/container is this library's only
runtime dependency and it stays that way. Install the parser:

  composer require symfony/yaml

Or parse it yourself and hand the array to load(), which needs nothing:

  \$container->load(Yaml::parseFile('{$file}'));
TXT;
        return new self($message);
    }

    public static function definitionFileInvalid(string $file, string $reason): self
    {
        $message = <<<TXT
The definitions file '{$file}' could not be used: {$reason}.

A '.php' file must `return` an array of id => definition; a '.json' file must
contain a JSON object with the same shape.
TXT;
        return new self($message);
    }

    public static function invalidDefinition(string $id, string $reason, ?string $file = null): self
    {
        $origin = $file === null
            ? "The definition for '{$id}' is invalid"
            : "The definition for '{$id}' in '{$file}' is invalid";

        $message = <<<TXT
{$origin}: {$reason}.

A definition is either a class-string, or an array with at most one of
'singleton', 'value', 'factory' or 'alias', plus an optional 'tags' list.
TXT;
        return new self($message);
    }

    public static function lazyTargetNotConcrete(string $abstract, string $target): self
    {
        $hint = $abstract === $target
            ? "Give it a concrete class to build:\n  \$container->lazy('{$abstract}', YourConcreteClass::class);"
            : "'{$abstract}' was pointed at '{$target}', which is not one.";

        $message = <<<TXT
'{$target}' cannot be made lazy.
A lazy target must be a concrete, instantiable class: interfaces, abstract
classes and unknown class names leave nothing to build a lazy instance of.

{$hint}
TXT;
        return new self($message);
    }

    public static function instanceNotExtendable(): self
    {
        $message = <<<TXT
The passed instance is not extendable.
Only objects, arrays, and callables can be extended.

Ensure the service is one of these types before calling extend().
TXT;
        return new self($message);
    }

    public static function frozenInstanceExtend(string $id): self
    {
        $message = <<<TXT
The instance '{$id}' is frozen and cannot be extended.
Services become frozen after being accessed via get() to ensure consistency.

Extend the service before accessing it, or use remove() to unfreeze it first.
TXT;
        return new self($message);
    }

    public static function frozenInstanceOverride(string $id): self
    {
        $message = <<<TXT
The instance '{$id}' is frozen and cannot be overridden.
Services become frozen after being accessed via get() to ensure consistency.

Call remove('{$id}') before setting a new value, or avoid accessing it before replacement.
TXT;
        return new self($message);
    }

    public static function inheritedInstanceExtend(string $id): self
    {
        $message = <<<TXT
The instance '{$id}' belongs to a parent container and cannot be extended from a scope.
A scope never mutates what it inherits, and extending in place would.

Extend it on the container that registered it, or shadow it in this scope:
  \$scope->set('{$id}', static fn () => new Decorator(\$parent->get('{$id}')));
TXT;
        return new self($message);
    }

    public static function instanceProtected(string $id): self
    {
        $message = <<<TXT
The instance '{$id}' is protected and cannot be extended.
Protected closures are treated as values, not as service factories.

Remove the protect() wrapper if you need to extend this service.
TXT;
        return new self($message);
    }
}
