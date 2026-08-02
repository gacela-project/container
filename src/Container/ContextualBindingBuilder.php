<?php

declare(strict_types=1);

namespace Gacela\Container;

use Closure;
use Gacela\Container\Exception\ContainerException;
use RuntimeException;

use function is_array;
use function str_starts_with;

/**
 * Builder for creating contextual bindings.
 * Allows different concrete implementations based on the requesting class.
 *
 * @psalm-import-type ContextualBindingsMap from ContainerInterface
 *
 * @api
 */
final class ContextualBindingBuilder
{
    /** @var list<class-string> */
    private array $concrete = [];

    private ?string $needs = null;

    /**
     * @param ContextualBindingsMap $contextualBindings
     * @param Closure(class-string, string, mixed): void|null $onGive told about
     *   each binding as it is written, so the container can hand it to the
     *   scopes it has already created
     */
    public function __construct(
        private array &$contextualBindings,
        private ?Closure $onGive = null,
    ) {
    }

    /**
     * Define which class(es) this binding applies to.
     *
     * @param class-string|list<class-string> $concrete
     */
    public function when(string|array $concrete): self
    {
        $this->concrete = is_array($concrete) ? $concrete : [$concrete];

        return $this;
    }

    /**
     * Define which dependency to bind. Accepts a class-string (bind by type) or
     * a `$`-prefixed constructor parameter name (bind by name, e.g. `'$apiKey'`).
     */
    public function needs(string $abstract): self
    {
        $this->needs = $abstract;

        return $this;
    }

    /**
     * Define what to give when the dependency is needed.
     *
     * `null` is a value like any other for a `$parameter` need — "this consumer
     * gets no logger" is a real answer. For a **type** need it is refused: the
     * absence of a binding is already how "nothing is bound" is expressed, so
     * binding a type to null could only ever be a mistake.
     *
     * @param mixed $implementation a class-string to resolve, a callable to
     *   invoke with the container, or any value to inject as-is — arrays,
     *   scalars and null included. Deliberately wider than `Binding`, which
     *   describes what `bind()` accepts, not this
     */
    public function give(mixed $implementation): void
    {
        $needs = $this->needs;

        if ($needs === null) {
            throw new RuntimeException('Must call needs() before give()');
        }

        if ($implementation === null && !str_starts_with($needs, '$')) {
            throw ContainerException::contextualNullForType($needs);
        }

        foreach ($this->concrete as $concreteClass) {
            if (!isset($this->contextualBindings[$concreteClass])) {
                $this->contextualBindings[$concreteClass] = [];
            }

            $this->contextualBindings[$concreteClass][$needs] = $implementation;

            if ($this->onGive !== null) {
                ($this->onGive)($concreteClass, $needs, $implementation);
            }
        }
    }
}
