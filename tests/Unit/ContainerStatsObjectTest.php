<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\ContainerStats;
use GacelaTest\Fake\ClassWithDependencyWithoutDependencies;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\RepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * `stats()` — the typed counterpart to `getStats()`.
 *
 * The array version cannot promise its shape, so it is carved out of the BC
 * policy. This one can, which is the whole point of it existing.
 */
final class ContainerStatsObjectTest extends TestCase
{
    public function test_it_reports_every_field(): void
    {
        $container = new Container([RepositoryInterface::class => ClassWithoutDependencies::class]);
        $container->set('service1', new ClassWithoutDependencies());
        $container->set('service2', new Person());
        $container->set('factory1', $container->factory(static fn (): object => new ClassWithoutDependencies()));
        $container->get('service1');
        $container->get(ClassWithDependencyWithoutDependencies::class);

        $stats = $container->stats();

        self::assertSame(3, $stats->registeredServices);
        self::assertSame(1, $stats->frozenServices);
        self::assertSame(1, $stats->factoryServices);
        self::assertSame(1, $stats->bindings);
        self::assertGreaterThan(0, $stats->cachedDependencies);
        self::assertGreaterThan(0, $stats->memoryUsageBytes);
    }

    public function test_it_reports_memory_as_a_number(): void
    {
        // The reason for the change: 'memory_usage' => "5.54 MB" has to be
        // parsed back before it can be compared or summed.
        $stats = (new Container())->stats();

        self::assertIsInt($stats->memoryUsageBytes);
    }

    public function test_it_formats_memory_on_demand(): void
    {
        $stats = new ContainerStats(0, 0, 0, 0, 0, 5_242_880);

        self::assertSame('5 MB', $stats->memoryUsageFormatted());
    }

    public function test_the_formatted_value_matches_the_array_version(): void
    {
        $container = new Container();

        $object = $container->stats();
        $array = $container->getStats();

        self::assertSame($array['memory_usage'], $object->memoryUsageFormatted());
    }

    public function test_both_apis_agree_on_every_shared_field(): void
    {
        // Two APIs over one set of numbers; they must not drift apart.
        $container = new Container([RepositoryInterface::class => ClassWithoutDependencies::class]);
        $container->set('service1', new ClassWithoutDependencies());
        $container->set('factory1', $container->factory(static fn (): object => new ClassWithoutDependencies()));
        $container->get('service1');

        $object = $container->stats();
        $array = $container->getStats();

        self::assertSame($array['registered_services'], $object->registeredServices);
        self::assertSame($array['frozen_services'], $object->frozenServices);
        self::assertSame($array['factory_services'], $object->factoryServices);
        self::assertSame($array['bindings'], $object->bindings);
        self::assertSame($array['cached_dependencies'], $object->cachedDependencies);
    }

    public function test_an_empty_container_reports_zeroes(): void
    {
        $stats = (new Container())->stats();

        self::assertSame(0, $stats->registeredServices);
        self::assertSame(0, $stats->frozenServices);
        self::assertSame(0, $stats->factoryServices);
        self::assertSame(0, $stats->bindings);
        self::assertSame(0, $stats->cachedDependencies);
    }
}
