<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

/**
 * Holds a service with an #[Inject] method so it can be reached as a *nested*
 * dependency, which is the only path served straight from the stored class
 * plan — a top-level get() answers off the process-wide method memo instead.
 */
final class OuterHoldingMethodInjectedService
{
    public function __construct(
        public ServiceWithInjectedMethod $inner,
    ) {
    }
}
