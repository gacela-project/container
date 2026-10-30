<?php

declare(strict_types=1);

namespace Gacela\Container;

use function is_object;

/**
 * @internal
 */
final class SingletonMemo
{
    private bool $hasResolved = false;

    private mixed $value = null;

    public function resolve(callable $factory, ?ContainerInterface $container): mixed
    {
        if (!$this->hasResolved) {
            /** @psalm-suppress MixedAssignment */
            $this->value = $factory($container);
            $this->hasResolved = true;
        }

        return $this->value;
    }

    public function forget(): void
    {
        $this->hasResolved = false;
        $this->value = null;
    }

    public function hasInstance(): bool
    {
        return $this->hasResolved && is_object($this->value);
    }
}
