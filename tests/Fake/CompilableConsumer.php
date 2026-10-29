<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class CompilableConsumer
{
    public function __construct(public CompilableDependency $dependency)
    {
    }
}
