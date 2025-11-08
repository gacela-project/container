<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

interface LoggerInterface
{
    public function log(string $message): void;
}
