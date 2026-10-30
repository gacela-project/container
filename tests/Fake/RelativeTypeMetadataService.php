<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Closure;
use Gacela\Container\Attribute\Inject;

final class RelativeTypeMetadataService extends CompilableDependency
{
    #[Inject]
    public self $selfDependency;

    #[Inject]
    public parent $parentDependency;

    public ?self $setterSelf = null;

    public ?parent $setterParent = null;

    public function __construct(public ?parent $constructorParent = null)
    {
    }

    #[Inject]
    public function configure(self $selfDependency, parent $parentDependency): void
    {
        $this->setterSelf = $selfDependency;
        $this->setterParent = $parentDependency;
    }

    public static function callback(): Closure
    {
        return static fn (self $selfDependency, parent $parentDependency): array => [$selfDependency, $parentDependency];
    }
}
