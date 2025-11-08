<?php

declare(strict_types=1);

namespace Gacela\Container;

use Closure;
use Gacela\Container\Exception\ContainerException;
use SplObjectStorage;

use function count;
use function is_array;
use function is_callable;
use function is_object;

/**
 * Manages factory instances, protected closures, and service extensions.
 */
final class FactoryManager
{
    /** @var SplObjectStorage<Closure, mixed> */
    private SplObjectStorage $factoryInstances;

    /** @var SplObjectStorage<Closure, mixed> */
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
        $this->factoryInstances->attach($instance);
    }

    /**
     * Mark a closure as protected (won't be invoked by container).
     */
    public function markAsProtected(Closure $instance): void
    {
        $this->protectedInstances->attach($instance);
    }

    /**
     * Check if an instance is marked as a factory.
     */
    public function isFactory(mixed $instance): bool
    {
        return $instance instanceof Closure && isset($this->factoryInstances[$instance]);
    }

    /**
     * Check if an instance is protected.
     */
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

    /**
     * Check if there are pending extensions for a service.
     */
    public function hasPendingExtensions(string $id): bool
    {
        return isset($this->instancesToExtend[$id]) && count($this->instancesToExtend[$id]) > 0;
    }

    /**
     * Get pending extensions for a service.
     *
     * @return list<Closure>
     */
    public function getPendingExtensions(string $id): array
    {
        return $this->instancesToExtend[$id] ?? [];
    }

    /**
     * Clear pending extensions for a service.
     */
    public function clearPendingExtensions(string $id): void
    {
        unset($this->instancesToExtend[$id]);
    }

    /**
     * Set the service ID currently being extended.
     */
    public function setCurrentlyExtending(?string $id): void
    {
        $this->currentlyExtending = $id;
    }

    /**
     * Check if we're currently extending a specific service.
     */
    public function isCurrentlyExtending(string $id): bool
    {
        return $this->currentlyExtending === $id;
    }

    /**
     * Transfer factory status from one instance to another.
     * Used when extending a factory service.
     */
    public function transferFactoryStatus(mixed $from, mixed $to): void
    {
        if ($from instanceof Closure && isset($this->factoryInstances[$from])) {
            $this->factoryInstances->detach($from);
            if ($to instanceof Closure) {
                $this->factoryInstances->attach($to);
            }
        }
    }

    /**
     * Generate an extended instance wrapper.
     *
     * @psalm-suppress MissingClosureReturnType,MixedAssignment
     */
    public function generateExtendedInstance(Closure $instance, mixed $factory, Container $container): Closure
    {
        if (is_callable($factory)) {
            return static function (Container $c) use ($instance, $factory) {
                $result = $factory($c);

                return $instance($result, $c) ?? $result;
            };
        }

        if (is_object($factory) || is_array($factory)) {
            return static fn (Container $c) => $instance($factory, $c) ?? $factory;
        }

        throw ContainerException::instanceNotExtendable();
    }
}
