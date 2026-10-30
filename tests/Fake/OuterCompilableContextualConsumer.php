<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class OuterCompilableContextualConsumer
{
    public function __construct(public CompilableContextualParent $consumer)
    {
    }
}
