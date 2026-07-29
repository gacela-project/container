<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use Closure;
use Gacela\Container\CompilationReport;
use Gacela\Container\ContainerStats;
use Gacela\Container\ContextualBindingBuilder;
use Gacela\Container\FullContainerInterface;
use Override;

/**
 * A container that wraps another and forwards everything to it.
 *
 * This is what FullContainerInterface is for. A decorator used to implement
 * ContainerInterface and then hand-write a forwarder per concrete-only method,
 * each with a comment explaining why the interface it implements did not cover
 * it — and nothing checked that the list stayed complete. Declaring this
 * interface makes the compiler check it: a method added to the contract fails
 * to compile here instead of silently going missing at runtime.
 *
 * Deliberately exhaustive rather than abbreviated. Its whole job as a fixture
 * is to be the thing that stops compiling.
 */
final class ForwardingContainer implements FullContainerInterface
{
    public function __construct(
        private FullContainerInterface $inner,
    ) {
    }

    #[Override]
    public function createScope(): static
    {
        return new self($this->inner->createScope());
    }

    #[Override]
    public function provides(string $id): bool
    {
        return $this->inner->provides($id);
    }

    #[Override]
    public function stats(): ContainerStats
    {
        return $this->inner->stats();
    }

    #[Override]
    public function load(array $definitions): void
    {
        $this->inner->load($definitions);
    }

    #[Override]
    public function loadFile(string $file): void
    {
        $this->inner->loadFile($file);
    }

    #[Override]
    public function lazy(string $abstract, callable|string|null $concrete = null): void
    {
        $this->inner->lazy($abstract, $concrete);
    }

    #[Override]
    public function taggedByKey(string $tag, string $key): mixed
    {
        return $this->inner->taggedByKey($tag, $key);
    }

    #[Override]
    public function taggedKeys(string $tag): array
    {
        return $this->inner->taggedKeys($tag);
    }

    #[Override]
    public function compileReport(array $classNames): CompilationReport
    {
        return $this->inner->compileReport($classNames);
    }

    #[Override]
    public function writeCompiledFactories(array $classNames, string $file, ?string $buildStamp = null): array
    {
        return $this->inner->writeCompiledFactories($classNames, $file, $buildStamp);
    }

    #[Override]
    public function useCompiledFactories(array $factories): void
    {
        $this->inner->useCompiledFactories($factories);
    }

    #[Override]
    public function get(string $id): mixed
    {
        return $this->inner->get($id);
    }

    #[Override]
    public function getOrFail(string $id): mixed
    {
        return $this->inner->getOrFail($id);
    }

    #[Override]
    public function make(string $className, array $parameters = []): object
    {
        return $this->inner->make($className, $parameters);
    }

    #[Override]
    public function resolve(callable $callable, array $parameters = []): mixed
    {
        return $this->inner->resolve($callable, $parameters);
    }

    #[Override]
    public function has(string $id): bool
    {
        return $this->inner->has($id);
    }

    #[Override]
    public function afterResolving(string $id, Closure $callback): void
    {
        $this->inner->afterResolving($id, $callback);
    }

    #[Override]
    public function bind(string $abstract, callable|object|string $concrete): void
    {
        $this->inner->bind($abstract, $concrete);
    }

    #[Override]
    public function singleton(string $abstract, callable|object|string|null $concrete = null): void
    {
        $this->inner->singleton($abstract, $concrete);
    }

    #[Override]
    public function bound(string $id): bool
    {
        return $this->inner->bound($id);
    }

    #[Override]
    public function bindIf(string $abstract, callable|object|string $concrete): void
    {
        $this->inner->bindIf($abstract, $concrete);
    }

    #[Override]
    public function singletonIf(string $abstract, callable|object|string|null $concrete = null): void
    {
        $this->inner->singletonIf($abstract, $concrete);
    }

    #[Override]
    public function set(string $id, mixed $instance): void
    {
        $this->inner->set($id, $instance);
    }

    #[Override]
    public function remove(string $id): void
    {
        $this->inner->remove($id);
    }

    #[Override]
    public function factory(Closure $instance): Closure
    {
        return $this->inner->factory($instance);
    }

    #[Override]
    public function extend(string $id, Closure $instance): Closure
    {
        return $this->inner->extend($id, $instance);
    }

    #[Override]
    public function protect(Closure $instance): Closure
    {
        return $this->inner->protect($instance);
    }

    #[Override]
    public function getRegisteredServices(): array
    {
        return $this->inner->getRegisteredServices();
    }

    #[Override]
    public function isFactory(string $id): bool
    {
        return $this->inner->isFactory($id);
    }

    #[Override]
    public function isFrozen(string $id): bool
    {
        return $this->inner->isFrozen($id);
    }

    #[Override]
    public function getBindings(): array
    {
        return $this->inner->getBindings();
    }

    #[Override]
    public function warmUp(array $classNames): void
    {
        $this->inner->warmUp($classNames);
    }

    #[Override]
    public function alias(string $alias, string $id): void
    {
        $this->inner->alias($alias, $id);
    }

    #[Override]
    public function tag(array|string $ids, string $tag): void
    {
        $this->inner->tag($ids, $tag);
    }

    #[Override]
    public function tagged(string $tag): iterable
    {
        return $this->inner->tagged($tag);
    }

    #[Override]
    public function getDependencyTree(string $className): array
    {
        return $this->inner->getDependencyTree($className);
    }

    #[Override]
    public function when(array|string $concrete): ContextualBindingBuilder
    {
        return $this->inner->when($concrete);
    }

    #[Override]
    public function compile(array $classNames): array
    {
        return $this->inner->compile($classNames);
    }

    #[Override]
    public function writeCompiledCache(array $classNames, string $file): void
    {
        $this->inner->writeCompiledCache($classNames, $file);
    }

    #[Override]
    public function getStats(): array
    {
        return $this->inner->getStats();
    }

    #[Override]
    public function offsetExists(mixed $offset): bool
    {
        return $this->inner->offsetExists($offset);
    }

    #[Override]
    public function offsetGet(mixed $offset): mixed
    {
        return $this->inner->offsetGet($offset);
    }

    #[Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->inner->offsetSet($offset, $value);
    }

    #[Override]
    public function offsetUnset(mixed $offset): void
    {
        $this->inner->offsetUnset($offset);
    }
}
