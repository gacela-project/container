<?php

declare(strict_types=1);

namespace GacelaTest\Fake\Graph;

final class Left
{
    public function __construct(
        public Shared $shared,
    ) {
    }
}
