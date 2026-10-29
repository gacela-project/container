<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class ServiceWithUntypedVariadicParameter
{
    public array $values;

    public function __construct(public string $label = 'default-label', ...$values)
    {
        $this->values = $values;
    }
}
