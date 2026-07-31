<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Inject;

final class ServiceOrderingConstructorPropertyAndMethod
{
    #[Inject]
    public ClassWithoutDependencies $injected;

    public bool $propertyWasSetFirst = false;

    public function __construct(public ClassWithoutDependencies $constructed)
    {
    }

    #[Inject]
    public function afterwards(ClassWithoutDependencies $dependency): void
    {
        $this->propertyWasSetFirst = isset($this->injected);
    }
}
