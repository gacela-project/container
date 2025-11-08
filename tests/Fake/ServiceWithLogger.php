<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class ServiceWithLogger
{
    public function __construct(
        public LoggerInterface $logger,
    ) {
    }
}
