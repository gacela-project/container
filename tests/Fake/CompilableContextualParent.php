<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

class CompilableContextualParent
{
    public function __construct(public ?CompilableDependency $dependency)
    {
    }
}
