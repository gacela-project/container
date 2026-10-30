<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class ServiceWithScalarVariadicParameter
{
    public array $values;

    public function __construct(string ...$values)
    {
        $this->values = $values;
    }
}
