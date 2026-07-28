<?php

declare(strict_types=1);

namespace GacelaBench\Fixture;

final class CallableHandler
{
    public function handle(Level4 $level4): Level4
    {
        return $level4;
    }
}
