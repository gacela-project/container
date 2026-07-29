<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use GacelaTest\Fake\ClassWithObjectDependencies;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\Person;
use PHPUnit\Framework\TestCase;

use function memory_get_usage;

/**
 * ContainerStats::memoryUsageBytes is the PHP process, not the container.
 *
 * Five of the object's six fields are container-scoped counters, so the sixth
 * reads as "what this container costs" — which is not the question it answers.
 * The value is documented rather than changed: measuring one container's
 * footprint would mean accounting code on the registration paths to feed a
 * debug field. These tests pin the documented meaning so the docs cannot drift
 * from it, and so a future rename is a deliberate act rather than a surprise.
 */
final class StatsMemoryIsProcessWideTest extends TestCase
{
    public function test_two_unrelated_containers_report_the_same_memory(): void
    {
        $empty = new Container();

        $busy = new Container();
        $busy->singleton(ClassWithoutDependencies::class);
        $busy->get(ClassWithObjectDependencies::class);
        $busy->get(Person::class);

        // If this were container-scoped, the one holding a singleton and two
        // resolved graphs could not match the one holding nothing.
        self::assertSame($empty->stats()->memoryUsageBytes, $busy->stats()->memoryUsageBytes);
    }

    public function test_the_container_scoped_fields_do_differ(): void
    {
        $empty = new Container();

        $busy = new Container();
        $busy->set('service', new ClassWithoutDependencies());
        $busy->get(ClassWithObjectDependencies::class);

        // Guards the guard: without this, the test above would pass on a
        // stats() that reported zeroes for everything.
        self::assertNotSame($empty->stats()->registeredServices, $busy->stats()->registeredServices);
        self::assertNotSame($empty->stats()->cachedDependencies, $busy->stats()->cachedDependencies);
    }

    public function test_it_reports_the_allocator_figure(): void
    {
        $container = new Container();

        // memory_get_usage(true) — real memory handed to the process by the
        // allocator, which moves in pages and so is stable across these calls.
        self::assertSame(memory_get_usage(true), $container->stats()->memoryUsageBytes);
    }

    public function test_a_scope_reports_the_same_number_as_its_parent(): void
    {
        $container = new Container();
        $scope = $container->createScope();

        $scope->get(ClassWithObjectDependencies::class);

        self::assertSame($container->stats()->memoryUsageBytes, $scope->stats()->memoryUsageBytes);
    }

    public function test_the_legacy_array_carries_the_same_figure(): void
    {
        $container = new Container();
        $stats = $container->stats();

        self::assertSame($stats->memoryUsageFormatted(), $container->getStats()['memory_usage']);
    }
}
