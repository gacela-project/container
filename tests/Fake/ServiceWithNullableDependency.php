<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

/**
 * A nullable dependency with no default, so nothing supplies it unless a
 * contextual binding does — which makes give(null) the only way to say
 * "this consumer gets none".
 */
final class ServiceWithNullableDependency
{
    public function __construct(
        public ?RepositoryInterface $repository,
    ) {
    }
}
