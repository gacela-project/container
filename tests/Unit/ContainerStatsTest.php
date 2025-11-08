<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\Person;
use PHPUnit\Framework\TestCase;

final class ContainerStatsTest extends TestCase
{
    public function test_get_stats_returns_correct_structure(): void
    {
        $container = new Container();

        $stats = $container->getStats();

        self::assertArrayHasKey('registered_services', $stats);
        self::assertArrayHasKey('frozen_services', $stats);
        self::assertArrayHasKey('factory_services', $stats);
        self::assertArrayHasKey('bindings', $stats);
        self::assertArrayHasKey('cached_dependencies', $stats);
        self::assertArrayHasKey('memory_usage', $stats);
    }

    public function test_stats_counts_registered_services(): void
    {
        $container = new Container();
        $container->set('service1', new ClassWithoutDependencies());
        $container->set('service2', new Person());

        $stats = $container->getStats();

        self::assertSame(2, $stats['registered_services']);
    }

    public function test_stats_counts_frozen_services(): void
    {
        $container = new Container();
        $container->set('service1', static fn () => new ClassWithoutDependencies());
        $container->set('service2', static fn () => new Person());

        // Access service1 to freeze it
        $container->get('service1');

        $stats = $container->getStats();

        self::assertSame(1, $stats['frozen_services']);
    }

    public function test_stats_counts_factory_services(): void
    {
        $container = new Container();
        $container->set('factory1', $container->factory(static fn () => new ClassWithoutDependencies()));
        $container->set('regular', static fn () => new Person());

        $stats = $container->getStats();

        self::assertSame(1, $stats['factory_services']);
    }

    public function test_stats_counts_bindings(): void
    {
        $container = new Container([
            'InterfaceA' => 'ConcreteA',
            'InterfaceB' => 'ConcreteB',
        ]);

        $stats = $container->getStats();

        self::assertSame(2, $stats['bindings']);
    }

    public function test_stats_includes_memory_usage(): void
    {
        $container = new Container();

        $stats = $container->getStats();

        self::assertIsString($stats['memory_usage']);
        self::assertMatchesRegularExpression('/^\d+(\.\d+)?\s+(B|KB|MB|GB)$/', $stats['memory_usage']);
    }

    public function test_stats_reflects_cached_dependencies_after_warmup(): void
    {
        $container = new Container();

        // Before warmup
        $statsBefore = $container->getStats();
        self::assertSame(0, $statsBefore['cached_dependencies']);

        // After warmup
        $container->warmUp([ClassWithoutDependencies::class, Person::class]);
        $statsAfter = $container->getStats();

        self::assertSame(2, $statsAfter['cached_dependencies']);
    }

    public function test_stats_updates_after_container_operations(): void
    {
        $container = new Container();

        $stats1 = $container->getStats();
        self::assertSame(0, $stats1['registered_services']);

        $container->set('service', new Person());

        $stats2 = $container->getStats();
        self::assertSame(1, $stats2['registered_services']);

        $container->remove('service');

        $stats3 = $container->getStats();
        self::assertSame(0, $stats3['registered_services']);
    }
}
