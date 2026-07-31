<?php

declare(strict_types=1);

namespace Gacela\Container;

use function implode;

/**
 * One thing that would fail to resolve, and how it was reached.
 *
 * The chain is the point. A missing binding four levels down is reported
 * against the class that declares it *and* the root that pulls it in, because
 * "which of my entry points does this break" is the question a build is asking.
 *
 * @api
 */
final class ValidationIssue
{
    /**
     * @param string $class the class or id the problem is in — not always a
     *   class-string, since an unbound interface or a typo'd id is exactly the
     *   kind of thing this reports
     * @param list<string> $chain the path from the root that reached it
     */
    public function __construct(
        public readonly string $class,
        public readonly ValidationProblem $problem,
        public readonly string $explanation,
        public readonly array $chain = [],
    ) {
    }

    /**
     * One line, ready to print: what is wrong, and the path to it.
     */
    public function describe(): string
    {
        $line = "[{$this->problem->value}] {$this->class}: {$this->explanation}";

        if ($this->chain === []) {
            return $line;
        }

        return $line . "\n    via " . implode(' -> ', $this->chain);
    }
}
