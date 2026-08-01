<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

/**
 * A constructor that needs itself: the shortest cycle there is, and the one a
 * builder's recursion has to refuse rather than follow.
 */
final class SelfReferential
{
    public function __construct(
        public SelfReferential $itself,
    ) {
    }
}
