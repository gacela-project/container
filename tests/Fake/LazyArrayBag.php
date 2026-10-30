<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use ArrayObject;
use Gacela\Container\Attribute\Inject;
use Gacela\Container\Attribute\Lazy;

#[Lazy]
class LazyArrayBag extends ArrayObject
{
    #[Inject]
    public ClassWithoutDependencies $propertyDependency;

    public ?ClassWithoutDependencies $setterDependency = null;

    public function __construct(public ClassWithoutDependencies $dependency)
    {
        parent::__construct(['configured' => 'internal state']);
        ConstructionCounter::record(static::class);
    }

    #[Inject]
    public function configure(ClassWithoutDependencies $dependency): void
    {
        $this->setterDependency = $dependency;
    }
}
