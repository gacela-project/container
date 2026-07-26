<?php

declare(strict_types=1);

namespace GacelaBench\Fixture;

use Gacela\Container\Attribute\Inject;

/** Resolved through the #[Inject] attribute path. */
final class WithInject
{
    public function __construct(
        #[Inject(ConsoleLogger::class)]
        public LoggerInterface $logger,
    ) {
    }
}
