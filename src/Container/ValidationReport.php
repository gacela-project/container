<?php

declare(strict_types=1);

namespace Gacela\Container;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function implode;

/**
 * What validate() found.
 *
 * The gap this closes is build-time feedback: a container that autowires tells
 * you a dependency is missing when a request resolves it, where a container
 * that compiles a definition set tells you at build time. Nothing here resolves
 * anything — every answer comes from a constructor plan and from asking the
 * container `has()`, so validating cannot construct a service or open a
 * connection.
 *
 * @api
 */
final class ValidationReport
{
    /**
     * @param list<ValidationIssue> $issues
     * @param list<string> $checked every class reached, problem or not
     */
    public function __construct(
        private readonly array $issues,
        private readonly array $checked,
    ) {
    }

    public function isValid(): bool
    {
        return $this->issues === [];
    }

    /**
     * @return list<ValidationIssue>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    /**
     * @return list<ValidationIssue>
     */
    public function issuesFor(string $class): array
    {
        return array_values(array_filter(
            $this->issues,
            static fn (ValidationIssue $issue): bool => $issue->class === $class,
        ));
    }

    /**
     * Every class the walk reached, whether or not it had a problem — so a
     * caller can tell "nothing wrong" from "nothing looked at".
     *
     * @return list<string>
     */
    public function checked(): array
    {
        return $this->checked;
    }

    public function count(): int
    {
        return count($this->issues);
    }

    /**
     * The whole report as text, one entry per issue. What the CLI prints.
     */
    public function render(): string
    {
        if ($this->issues === []) {
            return count($this->checked) . ' class(es) checked, no problems found.';
        }

        $lines = array_map(
            static fn (ValidationIssue $issue): string => $issue->describe(),
            $this->issues,
        );

        return count($this->checked) . ' class(es) checked, ' . count($this->issues) . " problem(s):\n\n"
            . implode("\n", $lines);
    }
}
