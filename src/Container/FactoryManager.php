<?php

declare(strict_types=1);

namespace Gacela\Container;

use Closure;
use Gacela\Container\Exception\ContainerException;
use WeakMap;

use function is_array;
use function is_callable;
use function is_object;

/**
 * Manages factory instances, protected closures, and service extensions.
 *
 * @internal
 * Not covered by backward compatibility: this class is an implementation
 * detail of Container and may change or disappear in any release
 */
final class FactoryManager
{
    /**
     * Weak, because a mark need only outlive the closure it marks.
     *
     * SplObjectStorage holds its keys strongly and nothing ever removed an
     * entry: there is no hook for a binding being overwritten or removed, and
     * factory() marks a closure before anyone decides whether to register it.
     * A long-lived container therefore retained every closure ever handed to
     * factory() or protect() — and everything each one closed over — whether
     * the binding still existed or ever existed at all.
     *
     * A WeakMap entry lasts exactly as long as anyone can still hold the
     * closure to ask about it, which is the real lifetime of the question.
     *
     * @var WeakMap<Closure, true>
     */
    private WeakMap $factoryInstances;

    /** @var WeakMap<Closure, true> */
    private WeakMap $protectedInstances;

    private ?string $currentlyExtending = null;

    /**
     * @param array<string, list<Closure>> $instancesToExtend
     */
    public function __construct(
        private array $instancesToExtend = [],
    ) {
        /** @var WeakMap<Closure, true> $factoryInstances */
        $factoryInstances = new WeakMap();
        $this->factoryInstances = $factoryInstances;

        /** @var WeakMap<Closure, true> $protectedInstances */
        $protectedInstances = new WeakMap();
        $this->protectedInstances = $protectedInstances;
    }

    /**
     * Mark a closure as a factory (always creates new instances).
     */
    public function markAsFactory(Closure $instance): void
    {
        $this->factoryInstances[$instance] = true;
    }

    /**
     * Mark a closure as protected (won't be invoked by container).
     */
    public function markAsProtected(Closure $instance): void
    {
        $this->protectedInstances[$instance] = true;
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
            unset($this->factoryInstances[$from]);
            $this->factoryInstances[$to] = true;
        }
    }

    public function generateExtendedInstance(Closure $instance, mixed $factory): Closure
    {
        if (is_callable($factory)) {
            return static function (ContainerInterface $c) use ($instance, $factory): mixed {
                /** @var mixed $result */
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
