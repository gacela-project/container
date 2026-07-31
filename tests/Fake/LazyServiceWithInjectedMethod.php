<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Inject;
use Gacela\Container\Attribute\Lazy;

#[Lazy]
final class LazyServiceWithInjectedMethod
{
    /** Static so a test can read it without touching — and initializing — the ghost. */
    public static int $calls = 0;

    public ?ClassWithoutDependencies $setterDependency = null;

    public function __construct(
        public ClassWithoutDependencies $dependency,
    ) {
    }

    #[Inject]
    public function setDependency(ClassWithoutDependencies $dependency): void
    {
        $this->setterDependency = $dependency;
        ++self::$calls;
    }
}
