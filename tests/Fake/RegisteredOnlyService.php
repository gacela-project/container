<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

/**
 * A service only a registration can produce: its constructor takes a scalar
 * with no default, so autowiring it throws.
 *
 * That is what makes it a probe. When something is registered under this id and
 * a resolution builds the class anyway, the failure names *this* class rather
 * than quietly handing back a second, differently-configured instance.
 */
final class RegisteredOnlyService
{
    public function __construct(
        public int $number,
    ) {
    }
}
