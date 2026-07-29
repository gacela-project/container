<?php

declare(strict_types=1);

namespace GacelaTest\Fake\Graph;

/**
 * A graph where one class's subtree genuinely differs by the path taken to it.
 *
 * CycleShared depends on CycleLeft, so:
 *
 * - reached via CycleLeft, that child is already an ancestor and is cut
 * - reached via CycleRight, CycleLeft is not an ancestor, so it expands one
 *   level further and cuts on CycleShared instead
 *
 * Any reuse of built subtrees has to refuse this one. It is the case that
 * separates "reuse a subtree that cannot depend on its path" from "reuse a
 * subtree and be wrong".
 */
final class CycleRoot
{
    public function __construct(
        public CycleLeft $left,
        public CycleRight $right,
    ) {
    }
}
