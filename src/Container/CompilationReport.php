<?php

declare(strict_types=1);

namespace Gacela\Container;

use function array_keys;

/**
 * What compilation decided about each class, and why.
 *
 * `writeCompiledFactories()` already tells you *which* classes were generated;
 * this tells you why the rest were not, so a build can assert on the outcome
 * instead of reading the source of an @internal class.
 *
 * Its `compiled()` set is the same one `writeCompiledFactories()` returns for
 * the same input — both are the generator's own verdict, not a second opinion.
 *
 * @api
 */
final class CompilationReport
{
    /**
     * @param list<class-string> $compiled
     * @param array<class-string, CompilationSkipReason> $reasons
     * @param array<class-string, string> $explanations one sentence per skipped class
     */
    public function __construct(
        private readonly array $compiled,
        private readonly array $reasons,
        private readonly array $explanations,
    ) {
    }

    /**
     * @return list<class-string>
     */
    public function compiled(): array
    {
        return $this->compiled;
    }

    /**
     * @return list<class-string>
     */
    public function skipped(): array
    {
        return array_keys($this->reasons);
    }

    /**
     * @param class-string $class
     */
    public function wasCompiled(string $class): bool
    {
        return !isset($this->reasons[$class]);
    }

    /**
     * Null when the class was compiled, or was never part of the input.
     *
     * @param class-string $class
     */
    public function reasonFor(string $class): ?CompilationSkipReason
    {
        return $this->reasons[$class] ?? null;
    }

    /**
     * A human-readable sentence naming the specific parameter or dependency that
     * blocked the class. Meant for build output; do not parse it.
     *
     * @param class-string $class
     */
    public function explain(string $class): ?string
    {
        return $this->explanations[$class] ?? null;
    }

    /**
     * Every skipped class mapped to its reason, for grouping or counting.
     *
     * @return array<class-string, CompilationSkipReason>
     */
    public function reasons(): array
    {
        return $this->reasons;
    }

    /**
     * Every skipped class mapped to its explanation, for printing in one go.
     *
     * @return array<class-string, string>
     */
    public function explanations(): array
    {
        return $this->explanations;
    }
}
