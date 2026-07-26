<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\ConstructionCounter;
use GacelaTest\Fake\EagerService;
use GacelaTest\Fake\LazyFactoryService;
use GacelaTest\Fake\LazyService;
use GacelaTest\Fake\LazySingletonService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class LazyServicesTest extends TestCase
{
    protected function setUp(): void
    {
        ConstructionCounter::reset();
    }

    public function test_a_lazy_service_is_not_constructed_on_resolution(): void
    {
        if (!self::supportsLazyObjects()) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $service = (new Container())->get(LazyService::class);

        self::assertInstanceOf(LazyService::class, $service);
        self::assertSame(0, ConstructionCounter::countFor(LazyService::class));
    }

    public function test_touching_a_property_constructs_the_service(): void
    {
        $service = (new Container())->get(LazyService::class);

        self::assertInstanceOf(ClassWithoutDependencies::class, $service->dependency);
        self::assertSame(1, ConstructionCounter::countFor(LazyService::class));
    }

    public function test_a_method_that_touches_no_state_leaves_it_uninitialized(): void
    {
        if (!self::supportsLazyObjects()) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $service = (new Container())->get(LazyService::class);

        // Lazy ghosts initialize on property access. A method that reads no
        // properties never needs the constructor, so it does not trigger one.
        self::assertSame('done', $service->work());
        self::assertSame(0, ConstructionCounter::countFor(LazyService::class));
    }

    public function test_a_lazy_service_resolves_its_dependencies_on_initialization(): void
    {
        $service = (new Container())->get(LazyService::class);

        self::assertInstanceOf(ClassWithoutDependencies::class, $service->dependency);
    }

    public function test_a_lazy_service_initializes_only_once(): void
    {
        $service = (new Container())->get(LazyService::class);

        $service->work();
        $service->work();
        self::assertInstanceOf(ClassWithoutDependencies::class, $service->dependency);

        self::assertSame(1, ConstructionCounter::countFor(LazyService::class));
    }

    public function test_a_class_without_the_attribute_is_constructed_eagerly(): void
    {
        (new Container())->get(EagerService::class);

        self::assertSame(1, ConstructionCounter::countFor(EagerService::class));
    }

    public function test_lazy_combined_with_singleton_shares_one_instance(): void
    {
        $container = new Container();

        $first = $container->get(LazySingletonService::class);
        $second = $container->get(LazySingletonService::class);

        self::assertSame($first, $second);

        // Touch it: still only one construction.
        self::assertInstanceOf(LazySingletonService::class, $first);
        $reflection = new ReflectionClass($first);
        $reflection->getProperties();

        self::assertLessThanOrEqual(1, ConstructionCounter::countFor(LazySingletonService::class));
    }

    public function test_lazy_combined_with_factory_yields_distinct_instances(): void
    {
        $container = new Container();

        $first = $container->get(LazyFactoryService::class);
        $second = $container->get(LazyFactoryService::class);

        self::assertNotSame($first, $second);
    }

    public function test_a_lazy_service_is_a_real_instance_not_a_proxy_subclass(): void
    {
        $service = (new Container())->get(LazyService::class);

        self::assertSame(LazyService::class, $service::class);
    }

    public function test_make_also_returns_a_lazy_instance(): void
    {
        if (!self::supportsLazyObjects()) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $service = (new Container())->make(LazyService::class);

        self::assertInstanceOf(LazyService::class, $service);
        self::assertSame(0, ConstructionCounter::countFor(LazyService::class));
    }

    public function test_a_lazy_service_can_be_injected_as_a_dependency(): void
    {
        $container = new Container();

        $resolved = $container->resolve(
            static fn (LazyService $service): LazyService => $service,
        );

        self::assertInstanceOf(LazyService::class, $resolved);
        self::assertInstanceOf(ClassWithoutDependencies::class, $resolved->dependency);
    }

    private static function supportsLazyObjects(): bool
    {
        return method_exists(ReflectionClass::class, 'newLazyGhost');
    }
}
