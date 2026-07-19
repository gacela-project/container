<?php

declare(strict_types=1);

namespace Gacela\Container;

use RuntimeException;

use function is_array;

/**
 * Builder for creating contextual bindings.
 * Allows different concrete implementations based on the requesting class.
 *
 * @psalm-import-type Binding from ContainerInterface
 * @psalm-import-type ContextualBindingsMap from ContainerInterface
 */
final class ContextualBindingBuilder
{
    /** @var list<class-string> */
    private array $concrete = [];

    private ?string $needs = null;

    /**
     * @param ContextualBindingsMap $contextualBindings
     */
    public function __construct(
        private array &$contextualBindings,
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
     * @param Binding $implementation
     */
    public function give(mixed $implementation): void
    {
        if ($this->needs === null) {
            throw new RuntimeException('Must call needs() before give()');
        }

        foreach ($this->concrete as $concreteClass) {
            if (!isset($this->contextualBindings[$concreteClass])) {
                /** @psalm-suppress PropertyTypeCoercion */
                $this->contextualBindings[$concreteClass] = [];
            }

            /** @psalm-suppress PropertyTypeCoercion */
            $this->contextualBindings[$concreteClass][$this->needs] = $implementation;
        }
    }
}
