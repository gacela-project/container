<?php

declare(strict_types=1);

namespace GacelaBench\Fixture;

use Gacela\Container\Attribute\Inject;

/**
 * The property-injection path, for comparison against WithInject: the same
 * dependency, reached after construction instead of through the constructor.
 */
final class WithInjectedProperty
{
    #[Inject(ConsoleLogger::class)]
    public LoggerInterface $logger;
}
