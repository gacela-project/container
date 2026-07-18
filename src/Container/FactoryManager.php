<?php

declare(strict_types=1);

namespace Gacela\Container;

use Closure;
use Gacela\Container\Exception\ContainerException;
use SplObjectStorage;

use function is_array;
use function is_callable;
use function is_object;

/**
 * Manages factory instances, protected closures, and service extensions.
 *
 * Note: SplObjectStorage is accessed via offsetSet()/offsetUnset() instead of
 * attach()/detach(), which are deprecated as of PHP 8.5. Behavior is identical.
 */
final class FactoryManager
{
    /** @var SplObjectStorage<Closure, null> */
    private SplObjectStorage $factoryInstances;

    /** @var SplObjectStorage<Closure, null> */
    private SplObjectStorage $protectedInstances;

    private ?string $currentlyExtending = null;

    /**
     * @param array<string, list<Closure>> $instancesToExtend
     */
    public function __construct(
        private array $instancesToExtend = [],
    ) {
        $this->factoryInstances = new SplObjectStorage();
        $this->protectedInstances = new SplObjectStorage();
    }

    /**
     * Mark a closure as a factory (always creates new instances).
     */
    public function markAsFactory(Closure $instance): void
    {
        $this->factoryInstances->offsetSet($instance, null);
    }

    /**
     * Mark a closure as protected (won't be invoked by container).
     */
    public function markAsProtected(Closure $instance): void
    {
        $this->protectedInstances->offsetSet($instance, null);
    }

    public function isFactory(mixed $instance): bool
    {
        return $instance instanceof Closure && isset($this->factoryInstances[$instance]);
    }

    public function isProtected(mixed $instance): bool
    {
        return $instance instanceof Closure && isset($this->protectedInstances[$instance]);
    }

    /**
     * Schedule an extension to be applied when the service is set.
     */
    public function scheduleExtension(string $id, Closure $instance): void
    {
        $this->instancesToExtend[$id][] = $instance;
    }

    public function hasPendingExtensions(string $id): bool
    {
        return isset($this->instancesToExtend[$id]);
    }

    /**
     * @return list<Closure>
     */
    public function getPendingExtensions(string $id): array
    {
        return $this->instancesToExtend[$id] ?? [];
    }

    public function clearPendingExtensions(string $id): void
    {
        unset($this->instancesToExtend[$id]);
    }

    public function setCurrentlyExtending(?string $id): void
    {
        $this->currentlyExtending = $id;
    }

    public function isCurrentlyExtending(string $id): bool
    {
        return $this->currentlyExtending === $id;
    }

    /**
     * Transfer factory status from one instance to another.
     * Used when extending a factory service.
     */
    public function transferFactoryStatus(mixed $from, Closure $to): void
    {
        if ($from instanceof Closure && isset($this->factoryInstances[$from])) {
            $this->factoryInstances->offsetUnset($from);
            $this->factoryInstances->offsetSet($to, null);
        }
    }

    /**
     * @psalm-suppress MixedAssignment
     */
    public function generateExtendedInstance(Closure $instance, mixed $factory): Closure
    {
        if (is_callable($factory)) {
            return static function (ContainerInterface $c) use ($instance, $factory): mixed {
                $result = $factory($c);

                return $instance($result, $c) ?? $result;
            };
        }

        if (is_object($factory) || is_array($factory)) {
            return static fn (ContainerInterface $c): mixed => $instance($factory, $c) ?? $factory;
        }

        throw ContainerException::instanceNotExtendable();
    }
}
