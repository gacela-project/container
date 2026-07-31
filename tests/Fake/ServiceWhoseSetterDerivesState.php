<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Gacela\Container\Attribute\Inject;

final class ServiceWhoseSetterDerivesState
{
    public string $describedBy = '';

    #[Inject]
    public function setRepository(RepositoryInterface $repository): void
    {
        $this->describedBy = $repository::class;
    }
}
