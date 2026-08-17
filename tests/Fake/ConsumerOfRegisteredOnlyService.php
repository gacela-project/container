<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

/**
 * Asks for {@see RegisteredOnlyService} by type, and is itself registered
 * nowhere — so resolving it is the container deciding what a constructor
 * parameter naming that id gets.
 */
final class ConsumerOfRegisteredOnlyService
{
    public function __construct(
        public RegisteredOnlyService $service,
    ) {
    }
}
