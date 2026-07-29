<?php

declare(strict_types=1);

namespace GacelaTest\Fake\Graph;

final class Shared
{
    public function __construct(
        public Leaf $leaf,
    ) {
    }
}
