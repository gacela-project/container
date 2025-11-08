<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class FileLogger implements LoggerInterface
{
    public function __construct(
        public string $filename,
        public bool $append = true,
    ) {
    }

    public function log(string $message): void
    {
        // Implementation not needed for tests
    }
}
