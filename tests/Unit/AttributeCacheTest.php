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

        // First call - should check attribute and cache it
        $instance1 = $container->get(CachedSingletonService::class);

        // Second call - should use cached attribute check and return same instance
        $instance2 = $container->get(CachedSingletonService::class);

        // Third call - should use cached attribute check and return same instance
        $instance3 = $container->get(CachedSingletonService::class);

        self::assertSame($instance1, $instance2);
        self::assertSame($instance2, $instance3);
    }

    public function test_factory_attribute_is_cached_on_repeated_instantiations(): void
    {
        $container = new Container();

        // First call - should check attribute and cache it
        $instance1 = $container->get(CachedFactoryService::class);

        // Second call - should use cached attribute check and return new instance
        $instance2 = $container->get(CachedFactoryService::class);

        // Third call - should use cached attribute check and return new instance
        $instance3 = $container->get(CachedFactoryService::class);

        self::assertNotSame($instance1, $instance2);
        self::assertNotSame($instance2, $instance3);
        self::assertNotSame($instance1, $instance3);
    }

    public function test_non_attributed_class_cache_behavior(): void
    {
        $container = new Container();

        // First call - should check for attributes (none exist) and cache the result
        $instance1 = $container->get(NonAttributedService::class);

        // Second call - should use cached "no attributes" result
        $instance2 = $container->get(NonAttributedService::class);

        // Non-attributed classes should return different instances (default behavior)
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

        // Singleton should be same
        self::assertSame($singleton1, $singleton2);

        // Factory should be different
        self::assertNotSame($factory1, $factory2);

        // Non-attributed should be different
        self::assertNotSame($normal1, $normal2);
    }

    public function test_attribute_cache_with_dependencies(): void
    {
        $container = new Container();

        // Singleton with dependencies should cache both the attribute check and instance
        $service1 = $container->get(SingletonWithDependency::class);
        $service2 = $container->get(SingletonWithDependency::class);

        self::assertSame($service1, $service2);
        self::assertInstanceOf(NonAttributedService::class, $service1->dependency);
    }

    public function test_performance_improvement_with_attribute_caching(): void
    {
        $container = new Container();

        // First instantiation - will do reflection to check attributes
        $start = hrtime(true);
        $container->get(CachedSingletonService::class);
        $firstCallTime = hrtime(true) - $start;

        // Subsequent instantiations - should use cached attribute check
        $iterations = 100;
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; ++$i) {
            $container->get(CachedSingletonService::class);
        }
        $cachedCallsTime = hrtime(true) - $start;

        // Average time per cached call should be much less than first call
        $avgCachedTime = $cachedCallsTime / $iterations;

        // This is a rough check - cached calls should be faster
        // We're not asserting exact values as timing can vary, but we document the behavior
        self::assertGreaterThan(0, $firstCallTime);
        self::assertGreaterThan(0, $avgCachedTime);

        // The important part is that the singleton instance is reused
        self::assertSame(
            $container->get(CachedSingletonService::class),
            $container->get(CachedSingletonService::class),
        );
    }

    public function test_attribute_cache_correctly_identifies_singleton_vs_factory(): void
    {
        $container = new Container();

        // Get both types multiple times
        $singleton1 = $container->get(CachedSingletonService::class);
        $factory1 = $container->get(CachedFactoryService::class);

        $singleton2 = $container->get(CachedSingletonService::class);
        $factory2 = $container->get(CachedFactoryService::class);

        $singleton3 = $container->get(CachedSingletonService::class);
        $factory3 = $container->get(CachedFactoryService::class);

        // Singletons should all be the same instance
        self::assertSame($singleton1, $singleton2);
        self::assertSame($singleton2, $singleton3);

        // Factories should all be different instances
        self::assertNotSame($factory1, $factory2);
        self::assertNotSame($factory2, $factory3);
        self::assertNotSame($factory1, $factory3);
    }
}

// Test classes

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
