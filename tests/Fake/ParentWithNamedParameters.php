<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Inject;

class ParentWithNamedParameters
{
    public mixed $setterValue = 'setter default';

    public function __construct(
        public string $apiKey,
        public mixed $value = 'constructor default',
    ) {
    }

    #[Inject]
    public function setValue(mixed $setterValue = 'setter default'): void
    {
        $this->setterValue = $setterValue;
    }
}
