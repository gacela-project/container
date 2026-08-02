<?php

declare(strict_types=1);

namespace Gacela\Container;

use Gacela\Container\Exception\ContainerException;

use function is_object;
use function method_exists;

/**
 * Manages service instance storage and frozen state.
 *
 * @internal
 * Not covered by backward compatibility: this class is an implementation
 * detail of Container and may change or disappear in any release
 */
final class InstanceRegistry
{
    /** @var array<string,mixed> */
    private array $instances = [];

    /** @var array<string,bool> */
    private array $frozenInstances = [];

    /**
     * Whether a class declares __invoke, keyed by class name.
     *
     * get() asked method_exists() on every read of a stored instance, to answer
     * a question the class settles once. Shared across containers, and keyed on
     * a class's shape like the resolver's memos are, so it is cleared by
     * Container::resetStaticCaches() for the same reason they are.
     *
     * @var array<class-string, bool>
     */
    private static array $invokable = [];

    /**
     * Drop the memo that outlives every container.
     *
     * See Container::resetStaticCaches(), the supported way in.
     */
    public static function resetCache(): void
    {
        self::$invokable = [];
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]);
    }

    /**
     * @throws ContainerException if instance is frozen
     */
    public function set(string $id, mixed $instance): void
    {
        if (isset($this->frozenInstances[$id])) {
            throw ContainerException::frozenInstanceOverride($id);
        }

        $this->instances[$id] = $instance;
    }

    /**
     * Resolve and return the instance; freezes it as a side effect.
     * Factory closures are re-invoked each call; protected closures are returned as-is.
     */
    public function get(string $id, FactoryManager $factoryManager, ContainerInterface $container): mixed
    {
        $this->frozenInstances[$id] = true;

        /** @var mixed $instance */
        $instance = $this->instances[$id];

        // The memo carries exactly what method_exists() used to prove inline —
        // and proved again on every single read of the instance. Both analysers
        // narrow an object to a callable from the call itself and cannot follow
        // that through the cache, hence the declaration below; the class
        // declaring __invoke is what makes the invocation safe, and a class
        // cannot stop declaring it within a process.
        if (!is_object($instance)
            || $factoryManager->isProtected($instance)
            || !(self::$invokable[$instance::class] ??= method_exists($instance, '__invoke'))
        ) {
            return $instance;
        }

        // What the memo proved, stated as a type rather than silenced as four
        // diagnostics. The parameter describes what the container passes, not
        // what the service is obliged to accept: an __invoke declaring nothing
        // is called exactly the same way and ignores it.
        /** @var object&callable(ContainerInterface): mixed $invokable */
        $invokable = $instance;

        if ($factoryManager->isFactory($instance)) {
            return $invokable($container);
        }

        /** @var mixed $resolvedService */
        $resolvedService = $invokable($container);

        $this->instances[$id] = $resolvedService;

        return $resolvedService;
    }

    /**
     * Remove a service instance and its frozen state.
     */
    public function remove(string $id): void
    {
        unset(
            $this->instances[$id],
            $this->frozenInstances[$id],
        );
    }

    public function isFrozen(string $id): bool
    {
        return isset($this->frozenInstances[$id]);
    }

    /**
     * @return list<string>
     */
    public function getAll(): array
    {
        return array_keys($this->instances);
    }

    /**
     * Get the stored value without invoking closures or freezing the service.
     */
    public function getRaw(string $id): mixed
    {
        return $this->instances[$id] ?? null;
    }
}
