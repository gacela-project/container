<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class ClassWithMissingClassDependency
{
    public function __construct(
        public readonly \App\Nope\Missing $missing,
    ) {
    }
}
