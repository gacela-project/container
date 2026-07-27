<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Inject;

/**
 * The attribute sits on a promoted constructor parameter, so it is also visible
 * as a property attribute. Only the constructor path may act on it.
 */
final class ServiceWithPromotedInject
{
    public function __construct(
        #[Inject(DatabaseRepository::class)]
        public RepositoryInterface $repository,
    ) {
    }
}
