<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Attribute\Factory;
use Gacela\Container\Attribute\Singleton;
use Gacela\Container\Container;
use PHPUnit\Framework\TestCase;

final class AttributeCacheTest extends TestCase
{
    public function test_singleton_attribute_is_cached_on_repeated_instantiations(): void
    {
        $container = new Container();

        $instance1 = $container->get(CachedSingletonService::class);
        $instance2 = $container->get(CachedSingletonService::class);
        $instance3 = $container->get(CachedSingletonService::class);

        self::assertSame($instance1, $instance2);
        self::assertSame($instance2, $instance3);
    }

    public function test_factory_attribute_is_cached_on_repeated_instantiations(): void
    {
        $container = new Container();

        $instance1 = $container->get(CachedFactoryService::class);
        $instance2 = $container->get(CachedFactoryService::class);
        $instance3 = $container->get(CachedFactoryService::class);

        self::assertNotSame($instance1, $instance2);
        self::assertNotSame($instance2, $instance3);
        self::assertNotSame($instance1, $instance3);
    }

    public function test_non_attributed_class_cache_behavior(): void
    {
        $container = new Container();

        $instance1 = $container->get(NonAttributedService::class);
        $instance2 = $container->get(NonAttributedService::class);

        // Non-attributed classes return a new instance on every get()
        self::assertNotSame($instance1, $instance2);
    }

    public function test_mixed_attribute_types_use_separate_cache_entries(): void
    {
        $container = new Container();

        $singleton1 = $container->get(CachedSingletonService::class);
        $factory1 = $container->get(CachedFactoryService::class);
        $normal1 = $container->get(NonAttributedService::class);

        $singleton2 = $container->get(CachedSingletonService::class);
        $factory2 = $container->get(CachedFactoryService::class);
        $normal2 = $container->get(NonAttributedService::class);

        self::assertSame($singleton1, $singleton2);
        self::assertNotSame($factory1, $factory2);
        self::assertNotSame($normal1, $normal2);
    }

    public function test_attribute_cache_with_dependencies(): void
    {
        $container = new Container();

        $service1 = $container->get(SingletonWithDependency::class);
        $service2 = $container->get(SingletonWithDependency::class);

        self::assertSame($service1, $service2);
        self::assertInstanceOf(NonAttributedService::class, $service1->dependency);
    }

    public function test_singleton_reused_across_many_repeated_gets(): void
    {
        $container = new Container();

        $first = $container->get(CachedSingletonService::class);

        for ($i = 0; $i < 100; ++$i) {
            self::assertSame($first, $container->get(CachedSingletonService::class));
        }
    }

    public function test_attribute_cache_correctly_identifies_singleton_vs_factory(): void
    {
        $container = new Container();

        $singleton1 = $container->get(CachedSingletonService::class);
        $factory1 = $container->get(CachedFactoryService::class);

        $singleton2 = $container->get(CachedSingletonService::class);
        $factory2 = $container->get(CachedFactoryService::class);

        $singleton3 = $container->get(CachedSingletonService::class);
        $factory3 = $container->get(CachedFactoryService::class);

        self::assertSame($singleton1, $singleton2);
        self::assertSame($singleton2, $singleton3);

        self::assertNotSame($factory1, $factory2);
        self::assertNotSame($factory2, $factory3);
        self::assertNotSame($factory1, $factory3);
    }
}

#[Singleton]
final class CachedSingletonService
{
}

#[Factory]
final class CachedFactoryService
{
}

final class NonAttributedService
{
}

#[Singleton]
final class SingletonWithDependency
{
    public function __construct(
        public NonAttributedService $dependency,
    ) {
    }
}
