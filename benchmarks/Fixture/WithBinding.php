<?php

declare(strict_types=1);

namespace GacelaBench\Fixture;

/** Resolved through an explicit interface binding. */
final class WithBinding
{
    public function __construct(
        public LoggerInterface $logger,
    ) {
    }
}
