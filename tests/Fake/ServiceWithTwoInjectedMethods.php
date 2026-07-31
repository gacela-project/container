<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Inject;

final class ServiceWithTwoInjectedMethods
{
    /** @var list<string> */
    public array $order = [];

    #[Inject]
    public function first(ClassWithoutDependencies $dependency): void
    {
        $this->order[] = 'first';
    }

    #[Inject]
    public function second(ClassWithoutDependencies $dependency): void
    {
        $this->order[] = 'second';
    }
}
