<?php

declare(strict_types=1);

namespace Gacela\Container;

use function array_merge;
use function count;
use function implode;

/**
 * One class in a dependency graph, and what it pulls in.
 *
 * `getDependencyTree()` returns a flat, deduplicated list of every class
 * reachable from a root. That answers "what does this touch", which is useful
 * and is not a tree — and the four things you open a dependency inspector for
 * are exactly what flattening removes: how deep something is, which class asked
 * for it, that the same class is pulled in by three different parents, and
 * where a cycle closes.
 *
 * A node keeps all four. Bindings are resolved as the graph is built, so an
 * interface appears as the concrete it maps to.
 *
 * @api
 */
final readonly class DependencyNode
{
    /**
     * @param class-string $className the concrete class, after bindings
     * @param string|null $parameter the constructor parameter this satisfies
     *   in its parent; null at the root, which satisfies nothing
     * @param list<DependencyNode> $children one per constructor parameter that
     *   takes a class, in declaration order
     * @param bool $repeated this class is already an ancestor of itself here,
     *   so the graph is cut at this node rather than recursed into. A cycle is
     *   reported rather than thrown, because inspecting a broken graph is
     *   precisely when this is reached for
     */
    public function __construct(
        public string $className,
        public ?string $parameter = null,
        public array $children = [],
        public bool $repeated = false,
    ) {
    }

    public function __toString(): string
    {
        return $this->render();
    }

    /**
     * Every class reachable from here, deduplicated, in the order first seen.
     *
     * The root is not included: it is what you asked about, not something it
     * depends on. This is what getDependencyTree() returns, derived from the
     * graph rather than walked separately, so the two cannot disagree.
     *
     * @return list<string>
     */
    public function flatten(): array
    {
        $seen = [];

        foreach ($this->children as $child) {
            $child->collectInto($seen);
        }

        /** @var list<string> */
        return array_keys($seen);
    }

    /**
     * How deep the graph goes below this node. A leaf is 0.
     */
    public function depth(): int
    {
        $deepest = 0;

        foreach ($this->children as $child) {
            $depth = $child->depth() + 1;
            if ($depth > $deepest) {
                $deepest = $depth;
            }
        }

        return $deepest;
    }

    /**
     * Whether a cycle was cut anywhere at or below this node.
     */
    public function hasCycle(): bool
    {
        if ($this->repeated) {
            return true;
        }

        foreach ($this->children as $child) {
            if ($child->hasCycle()) {
                return true;
            }
        }

        return false;
    }

    /**
     * An indented text tree, for reading rather than parsing:
     *
     * ```
     * App\OrderService
     * ├── $repository: App\OrderRepository
     * │   └── $db: App\Db
     * └── $clock: App\SystemClock
     * ```
     *
     * A cut cycle is marked, since a tree that simply stopped would look like
     * one that had nothing more to say.
     */
    public function render(): string
    {
        return implode("\n", $this->lines('', true, true));
    }

    /**
     * @param array<string, true> $seen
     */
    private function collectInto(array &$seen): void
    {
        if (isset($seen[$this->className])) {
            return;
        }

        $seen[$this->className] = true;

        foreach ($this->children as $child) {
            $child->collectInto($seen);
        }
    }

    /**
     * @return list<string>
     */
    private function lines(string $prefix, bool $isLast, bool $isRoot): array
    {
        $label = $this->parameter === null
            ? $this->className
            : '$' . $this->parameter . ': ' . $this->className;

        if ($this->repeated) {
            $label .= ' (cycle)';
        }

        $lines = [$isRoot ? $label : $prefix . ($isLast ? '└── ' : '├── ') . $label];

        // The root's children hang off nothing, so they carry no inherited
        // prefix; every other level continues its parent's guides.
        $childPrefix = $isRoot ? '' : $prefix . ($isLast ? '    ' : '│   ');

        $lastIndex = count($this->children) - 1;

        foreach ($this->children as $index => $child) {
            $lines = array_merge($lines, $child->lines($childPrefix, $index === $lastIndex, false));
        }

        return $lines;
    }
}
