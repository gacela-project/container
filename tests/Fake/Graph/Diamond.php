<?php

declare(strict_types=1);

namespace GacelaTest\Fake\Graph;

/**
 * A diamond: two branches that both end at Shared.
 *
 * Root
 * ├── $left:  Left  → $shared: Shared → $leaf: Leaf
 * └── $right: Right → $shared: Shared → $leaf: Leaf
 *
 * Flattening reports Shared once and cannot say that two parents ask for it.
 * The graph says it twice, which is the difference the whole feature is about.
 */
final class Diamond
{
    public function __construct(
        public Left $left,
        public Right $right,
    ) {
    }
}
