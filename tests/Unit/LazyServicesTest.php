<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\Exception\ContainerException;
use GacelaTest\Fake\AbstractService;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\ConstructionCounter;
use GacelaTest\Fake\EagerService;
use GacelaTest\Fake\ExpensiveReportGenerator;
use GacelaTest\Fake\LazyFactoryService;
use GacelaTest\Fake\LazyService;
use GacelaTest\Fake\LazySingletonService;
use GacelaTest\Fake\OuterHoldingLazyService;
use GacelaTest\Fake\OuterHoldingReportGenerator;
use GacelaTest\Fake\ReportGeneratorInterface;
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

    public function test_an_injected_lazy_dependency_is_not_constructed_with_its_holder(): void
    {
        if (!self::supportsLazyObjects()) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $outer = (new Container())->get(OuterHoldingLazyService::class);

        self::assertInstanceOf(OuterHoldingLazyService::class, $outer);
        self::assertSame(0, ConstructionCounter::countFor(LazyService::class));

        self::assertInstanceOf(ClassWithoutDependencies::class, $outer->lazyService->dependency);
        self::assertSame(1, ConstructionCounter::countFor(LazyService::class));
    }

    public function test_lazy_defers_a_class_that_carries_no_attribute(): void
    {
        if (!self::supportsLazyObjects()) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $container = new Container();
        $container->lazy(ExpensiveReportGenerator::class);

        $generator = $container->get(ExpensiveReportGenerator::class);

        self::assertSame(ExpensiveReportGenerator::class, $generator::class);
        self::assertSame(0, ConstructionCounter::countFor(ExpensiveReportGenerator::class));
    }

    public function test_a_lazy_registered_class_constructs_on_first_touch(): void
    {
        $container = new Container();
        $container->lazy(ExpensiveReportGenerator::class);

        $generator = $container->get(ExpensiveReportGenerator::class);

        self::assertInstanceOf(ClassWithoutDependencies::class, $generator->dependency);
        self::assertSame(1, ConstructionCounter::countFor(ExpensiveReportGenerator::class));
    }

    public function test_lazy_binds_an_abstract_to_a_deferred_concrete(): void
    {
        if (!self::supportsLazyObjects()) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $container = new Container();
        $container->lazy(ReportGeneratorInterface::class, ExpensiveReportGenerator::class);

        $generator = $container->get(ReportGeneratorInterface::class);

        self::assertInstanceOf(ExpensiveReportGenerator::class, $generator);
        self::assertSame(0, ConstructionCounter::countFor(ExpensiveReportGenerator::class));
        self::assertTrue($container->bound(ReportGeneratorInterface::class));
    }

    public function test_a_lazy_closure_binding_does_not_run_on_resolution(): void
    {
        if (!self::supportsLazyObjects()) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $closureCalls = 0;
        $container = new Container();
        $container->lazy(
            ExpensiveReportGenerator::class,
            static function (Container $c) use (&$closureCalls): ExpensiveReportGenerator {
                ++$closureCalls;

                return new ExpensiveReportGenerator($c->get(ClassWithoutDependencies::class));
            },
        );

        $generator = $container->get(ExpensiveReportGenerator::class);

        self::assertSame(0, $closureCalls);
        self::assertSame(ExpensiveReportGenerator::class, $generator::class);

        self::assertInstanceOf(ClassWithoutDependencies::class, $generator->dependency);
        self::assertSame(1, $closureCalls);
    }

    public function test_a_lazy_closure_binding_runs_once_per_instance(): void
    {
        $closureCalls = 0;
        $container = new Container();
        $container->lazy(
            ExpensiveReportGenerator::class,
            static function (Container $c) use (&$closureCalls): ExpensiveReportGenerator {
                ++$closureCalls;

                return new ExpensiveReportGenerator($c->get(ClassWithoutDependencies::class));
            },
        );

        $generator = $container->get(ExpensiveReportGenerator::class);

        self::assertInstanceOf(ClassWithoutDependencies::class, $generator->dependency);
        self::assertInstanceOf(ClassWithoutDependencies::class, $generator->dependency);
        self::assertSame(1, $closureCalls);
    }

    public function test_lazy_is_honoured_through_make_and_get_or_fail(): void
    {
        if (!self::supportsLazyObjects()) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $container = new Container();
        $container->lazy(ExpensiveReportGenerator::class);

        self::assertInstanceOf(ExpensiveReportGenerator::class, $container->make(ExpensiveReportGenerator::class));
        self::assertInstanceOf(ExpensiveReportGenerator::class, $container->getOrFail(ExpensiveReportGenerator::class));
        self::assertSame(0, ConstructionCounter::countFor(ExpensiveReportGenerator::class));
    }

    public function test_lazy_is_honoured_for_an_injected_dependency(): void
    {
        if (!self::supportsLazyObjects()) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $container = new Container();
        $container->lazy(ExpensiveReportGenerator::class);

        $outer = $container->get(OuterHoldingReportGenerator::class);

        self::assertSame(0, ConstructionCounter::countFor(ExpensiveReportGenerator::class));

        self::assertInstanceOf(ClassWithoutDependencies::class, $outer->reportGenerator->dependency);
        self::assertSame(1, ConstructionCounter::countFor(ExpensiveReportGenerator::class));
    }

    public function test_a_lazy_closure_binding_is_honoured_for_an_injected_dependency(): void
    {
        if (!self::supportsLazyObjects()) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $closureCalls = 0;
        $container = new Container();
        $container->lazy(
            ExpensiveReportGenerator::class,
            static function (Container $c) use (&$closureCalls): ExpensiveReportGenerator {
                ++$closureCalls;

                return new ExpensiveReportGenerator($c->get(ClassWithoutDependencies::class));
            },
        );

        $outer = $container->get(OuterHoldingReportGenerator::class);

        self::assertSame(0, $closureCalls);

        self::assertInstanceOf(ClassWithoutDependencies::class, $outer->reportGenerator->dependency);
        self::assertSame(1, $closureCalls);
    }

    public function test_a_scope_inherits_a_lazy_closure_registration(): void
    {
        if (!self::supportsLazyObjects()) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $closureCalls = 0;
        $container = new Container();
        $container->lazy(
            ExpensiveReportGenerator::class,
            static function (Container $c) use (&$closureCalls): ExpensiveReportGenerator {
                ++$closureCalls;

                return new ExpensiveReportGenerator($c->get(ClassWithoutDependencies::class));
            },
        );

        $generator = $container->createScope()->get(ExpensiveReportGenerator::class);

        self::assertSame(0, $closureCalls);
        self::assertInstanceOf(ClassWithoutDependencies::class, $generator->dependency);
        self::assertSame(1, $closureCalls);
    }

    public function test_a_scope_does_not_compile_what_its_parent_made_lazy(): void
    {
        $container = new Container();
        $container->lazy(ExpensiveReportGenerator::class);

        $file = tempnam(sys_get_temp_dir(), 'compiled') . '.php';
        $compiled = $container->createScope()->writeCompiledFactories([ExpensiveReportGenerator::class], $file);
        @unlink($file);

        self::assertNotContains(ExpensiveReportGenerator::class, $compiled);
    }

    public function test_singleton_combined_with_lazy_shares_one_deferred_instance(): void
    {
        $container = new Container();
        $container->singleton(ExpensiveReportGenerator::class);
        $container->lazy(ExpensiveReportGenerator::class);

        $first = $container->get(ExpensiveReportGenerator::class);
        $second = $container->get(ExpensiveReportGenerator::class);

        self::assertSame($first, $second);

        self::assertInstanceOf(ClassWithoutDependencies::class, $first->dependency);
        self::assertSame(1, ConstructionCounter::countFor(ExpensiveReportGenerator::class));
    }

    public function test_the_attribute_and_lazy_agree_when_both_are_used(): void
    {
        if (!self::supportsLazyObjects()) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $container = new Container();
        $container->lazy(LazyService::class);

        $service = $container->get(LazyService::class);

        self::assertSame(LazyService::class, $service::class);
        self::assertSame(0, ConstructionCounter::countFor(LazyService::class));
    }

    public function test_a_scope_inherits_a_lazy_registration(): void
    {
        if (!self::supportsLazyObjects()) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $container = new Container();
        $container->lazy(ExpensiveReportGenerator::class);

        $generator = $container->createScope()->get(ExpensiveReportGenerator::class);

        self::assertInstanceOf(ExpensiveReportGenerator::class, $generator);
        self::assertSame(0, ConstructionCounter::countFor(ExpensiveReportGenerator::class));
    }

    public function test_a_lazy_registration_on_a_scope_does_not_leak_to_its_parent(): void
    {
        $container = new Container();
        $scope = $container->createScope();
        $scope->lazy(ExpensiveReportGenerator::class);

        $container->get(ExpensiveReportGenerator::class);

        self::assertSame(1, ConstructionCounter::countFor(ExpensiveReportGenerator::class));
    }

    public function test_a_lazy_service_is_not_reported_as_instantiated_before_it_is_touched(): void
    {
        if (!self::supportsLazyObjects()) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $container = new Container();
        $container->lazy(ExpensiveReportGenerator::class);
        $container->get(ExpensiveReportGenerator::class);

        self::assertSame([], $container->getRegisteredServices());
        self::assertSame(0, $container->stats()->registeredServices);
        self::assertSame(0, ConstructionCounter::countFor(ExpensiveReportGenerator::class));
    }

    public function test_lazy_rejects_an_unknown_class(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("'NotAClassAtAll' cannot be made lazy.");

        (new Container())->lazy('NotAClassAtAll');
    }

    public function test_lazy_rejects_an_interface_without_a_concrete(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage(ReportGeneratorInterface::class . "', YourConcreteClass::class);");

        (new Container())->lazy(ReportGeneratorInterface::class);
    }

    public function test_lazy_rejects_a_non_instantiable_concrete(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('which is not one.');

        (new Container())->lazy(ReportGeneratorInterface::class, AbstractService::class);
    }

    public function test_lazy_rejects_a_closure_bound_to_an_interface(): void
    {
        // A proxy has to be an instance of something, and only the abstract
        // names a type here.
        $this->expectException(ContainerException::class);

        (new Container())->lazy(
            ReportGeneratorInterface::class,
            static fn (): ExpensiveReportGenerator => new ExpensiveReportGenerator(new ClassWithoutDependencies()),
        );
    }

    private static function supportsLazyObjects(): bool
    {
        return method_exists(ReflectionClass::class, 'newLazyGhost');
    }
}
