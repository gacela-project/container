<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\ContainerInterface;
use Gacela\Container\DependencyResolver;
use Gacela\Container\Exception\ContainerException;
use GacelaTest\Fake\AbstractService;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\ConstructionCounter;
use GacelaTest\Fake\EagerService;
use GacelaTest\Fake\ExpensiveReportGenerator;
use GacelaTest\Fake\LazyFactoryService;
use GacelaTest\Fake\LazyService;
use GacelaTest\Fake\LazyServiceWithoutConstructor;
use GacelaTest\Fake\LazyServiceWithoutProperties;
use GacelaTest\Fake\LazySingletonService;
use GacelaTest\Fake\OuterHoldingLazyService;
use GacelaTest\Fake\OuterHoldingLazySingletonService;
use GacelaTest\Fake\OuterHoldingReportGenerator;
use GacelaTest\Fake\ReplacementLazySingletonService;
use GacelaTest\Fake\ReportGeneratorInterface;
use GacelaTest\Fake\ServiceWithInjectedMethod;
use GacelaTest\Fake\ServiceWithInjectedProperty;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

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

    public function test_a_lazy_class_without_properties_is_constructed(): void
    {
        $service = (new Container())->get(LazyServiceWithoutProperties::class);

        self::assertInstanceOf(LazyServiceWithoutProperties::class, $service);
        self::assertSame(1, ConstructionCounter::countFor(LazyServiceWithoutProperties::class));
    }

    public function test_a_lazy_factory_for_a_class_without_properties_returns_what_it_builds(): void
    {
        $built = new LazyServiceWithoutProperties(new ClassWithoutDependencies());
        $container = new Container();
        $container->lazy(LazyServiceWithoutProperties::class, static fn (): LazyServiceWithoutProperties => $built);

        self::assertSame($built, $container->get(LazyServiceWithoutProperties::class));
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

    #[DataProvider('lazySingletonResolutionProvider')]
    public function test_a_lazy_singleton_is_shared_by_direct_and_nested_resolution(bool $directFirst, bool $forceEager): void
    {
        if (!$forceEager && !self::supportsLazyObjects()) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $available = new ReflectionProperty(DependencyResolver::class, 'lazyObjectsAvailable');
        $previous = $available->getValue();
        if ($forceEager) {
            $available->setValue(null, false);
        }

        try {
            $container = new Container();
            $direct = $directFirst ? $container->get(LazySingletonService::class) : null;
            $first = $container->get(OuterHoldingLazySingletonService::class)->lazyService;
            $second = $container->get(OuterHoldingLazySingletonService::class)->lazyService;
            $direct ??= $container->get(LazySingletonService::class);

            self::assertSame($direct, $first);
            self::assertSame($first, $second);
            self::assertSame($forceEager ? 1 : 0, ConstructionCounter::countFor(LazySingletonService::class));

            foreach ([$direct, $first, $second] as $service) {
                ++$service->value;
            }

            self::assertSame(3, $direct->value);
            self::assertSame(1, ConstructionCounter::countFor(LazySingletonService::class));
        } finally {
            if ($forceEager) {
                $available->setValue(null, $previous);
            }
        }
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function lazySingletonResolutionProvider(): iterable
    {
        yield 'direct first, native' => [true, false];
        yield 'nested first, native' => [false, false];
        yield 'direct first, eager fallback' => [true, true];
        yield 'nested first, eager fallback' => [false, true];
    }

    #[DataProvider('lazySingletonResolutionProvider')]
    public function test_a_contextual_lazy_singleton_keeps_its_target_and_shared_cache(bool $directFirst, bool $forceEager): void
    {
        if (!$forceEager && !self::supportsLazyObjects()) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $available = new ReflectionProperty(DependencyResolver::class, 'lazyObjectsAvailable');
        $previous = $available->getValue();
        if ($forceEager) {
            $available->setValue(null, false);
        }

        try {
            $container = new Container();
            $direct = $directFirst ? $container->get(LazySingletonService::class) : null;
            $container->bind(LazySingletonService::class, ReplacementLazySingletonService::class);
            $container->when(OuterHoldingLazySingletonService::class)
                ->needs(LazySingletonService::class)->give(LazySingletonService::class);

            $first = $container->get(OuterHoldingLazySingletonService::class)->lazyService;
            self::assertSame(LazySingletonService::class, $first::class);
            self::assertSame($first, $container->get(OuterHoldingLazySingletonService::class)->lazyService);
            if ($directFirst) {
                self::assertSame($direct, $first);
            }
            self::assertSame($forceEager ? 1 : 0, ConstructionCounter::countFor(LazySingletonService::class));
            ++$first->value;
            self::assertSame(1, ConstructionCounter::countFor(LazySingletonService::class));
            self::assertInstanceOf(ReplacementLazySingletonService::class, $container->get(LazySingletonService::class));

            $container->forget(LazySingletonService::class);
            $rebuilt = $container->get(OuterHoldingLazySingletonService::class)->lazyService;
            self::assertSame(LazySingletonService::class, $rebuilt::class);
            self::assertNotSame($first, $rebuilt);
            self::assertSame($rebuilt, $container->get(OuterHoldingLazySingletonService::class)->lazyService);
            self::assertSame(0, $rebuilt->value);
            self::assertSame(2, ConstructionCounter::countFor(LazySingletonService::class));
        } finally {
            if ($forceEager) {
                $available->setValue(null, $previous);
            }
        }
    }

    public function test_a_failed_contextual_singleton_hook_discards_the_cached_instance(): void
    {
        $container = new Container();
        $container->bind(LazySingletonService::class, ReplacementLazySingletonService::class);
        $container->when(OuterHoldingLazySingletonService::class)
            ->needs(LazySingletonService::class)->give(LazySingletonService::class);
        $calls = 0;
        $container->afterResolving(LazySingletonService::class, static function (LazySingletonService $service) use (&$calls): void {
            ++$calls;
            ++$service->value;
            if ($calls === 1) {
                throw new RuntimeException('Failed configuration');
            }
        });

        try {
            $container->get(OuterHoldingLazySingletonService::class);
            self::fail('The first hook must throw');
        } catch (RuntimeException) {
            self::assertSame(1, $calls);
        }

        $first = $container->get(OuterHoldingLazySingletonService::class)->lazyService;
        self::assertSame($first, $container->get(OuterHoldingLazySingletonService::class)->lazyService);
        self::assertSame(1, $first->value);
        self::assertSame(2, $calls);
        self::assertSame(2, ConstructionCounter::countFor(LazySingletonService::class));
    }

    #[DataProvider('lazySingletonModes')]
    public function test_contextual_lazy_singletons_keep_scope_ownership(bool $forceEager): void
    {
        if (!$forceEager && !self::supportsLazyObjects()) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $available = new ReflectionProperty(DependencyResolver::class, 'lazyObjectsAvailable');
        $previous = $available->getValue();
        if ($forceEager) {
            $available->setValue(null, false);
        }

        try {
            $parent = new Container();
            $parent->when(OuterHoldingLazySingletonService::class)
                ->needs(LazySingletonService::class)->give(LazySingletonService::class);
            $parentInstance = $parent->get(LazySingletonService::class);
            $scope = $parent->createScope();
            self::assertSame($parentInstance, $scope->get(OuterHoldingLazySingletonService::class)->lazyService);

            $parent = new Container();
            $parent->bind(LazySingletonService::class, ReplacementLazySingletonService::class);
            $parent->when(OuterHoldingLazySingletonService::class)
                ->needs(LazySingletonService::class)->give(LazySingletonService::class);
            $scope = $parent->createScope();
            $sibling = $parent->createScope();
            $first = $scope->get(OuterHoldingLazySingletonService::class)->lazyService;
            self::assertSame(LazySingletonService::class, $first::class);
            self::assertSame($first, $scope->get(OuterHoldingLazySingletonService::class)->lazyService);
            self::assertNotSame($first, $sibling->get(OuterHoldingLazySingletonService::class)->lazyService);

            $scope->forget(LazySingletonService::class);
            self::assertNotSame($first, $scope->get(OuterHoldingLazySingletonService::class)->lazyService);
        } finally {
            if ($forceEager) {
                $available->setValue(null, $previous);
            }
        }
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function lazySingletonModes(): iterable
    {
        yield 'native' => [false];
        yield 'eager fallback' => [true];
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

    public function test_a_lazy_attribute_without_a_constructor_preserves_defaults_and_injects_on_first_touch(): void
    {
        $builds = 0;
        $container = new Container([
            ClassWithoutDependencies::class => static function () use (&$builds): ClassWithoutDependencies {
                ++$builds;

                return new ClassWithoutDependencies();
            },
        ]);
        $service = $container->get(LazyServiceWithoutConstructor::class);
        self::assertInstanceOf(LazyServiceWithoutConstructor::class, $service);
        if (self::supportsLazyObjects()) {
            self::assertSame(0, $builds);
        }

        self::assertSame(3, $service->retries);
        self::assertInstanceOf(ClassWithoutDependencies::class, $service->propertyDependency);
        self::assertInstanceOf(ClassWithoutDependencies::class, $service->setterDependency);
        self::assertSame(1, $service->setterCalls);
        self::assertSame(2, $builds);
    }

    public function test_a_lazy_registered_class_without_a_constructor_injects_properties(): void
    {
        $container = new Container();
        $container->lazy(ServiceWithInjectedProperty::class);

        $service = $container->get(ServiceWithInjectedProperty::class);

        self::assertInstanceOf(ServiceWithInjectedProperty::class, $service);
        self::assertInstanceOf(ClassWithoutDependencies::class, $service->dependency());
    }

    public function test_a_lazy_registered_class_without_a_constructor_injects_methods(): void
    {
        $container = new Container();
        $container->lazy(ServiceWithInjectedMethod::class);

        $service = $container->get(ServiceWithInjectedMethod::class);

        self::assertInstanceOf(ServiceWithInjectedMethod::class, $service);
        self::assertInstanceOf(ClassWithoutDependencies::class, $service->dependency);
        self::assertSame(1, $service->calls);
    }

    public function test_classes_without_constructors_keep_injection_in_the_eager_fallback(): void
    {
        $available = new ReflectionProperty(DependencyResolver::class, 'lazyObjectsAvailable');
        $available->setValue(null, false);
        try {
            $builds = 0;
            $container = new Container([
                ClassWithoutDependencies::class => static function () use (&$builds): ClassWithoutDependencies {
                    ++$builds;

                    return new ClassWithoutDependencies();
                },
            ]);
            $container->lazy(ServiceWithInjectedMethod::class);
            $attribute = $container->get(LazyServiceWithoutConstructor::class);
            self::assertSame(2, $builds);
            $registered = $container->get(ServiceWithInjectedMethod::class);
            self::assertSame(3, $builds);

            self::assertInstanceOf(LazyServiceWithoutConstructor::class, $attribute);
            self::assertSame(3, $attribute->retries);
            self::assertInstanceOf(ClassWithoutDependencies::class, $attribute->propertyDependency);
            self::assertInstanceOf(ClassWithoutDependencies::class, $attribute->setterDependency);
            self::assertSame(1, $attribute->setterCalls);
            self::assertInstanceOf(ServiceWithInjectedMethod::class, $registered);
            self::assertInstanceOf(ClassWithoutDependencies::class, $registered->dependency);
            self::assertSame(1, $registered->calls);
        } finally {
            Container::resetStaticCaches();
        }
    }

    #[DataProvider('callableLazyFactoryProvider')]
    public function test_callable_lazy_factories_preserve_direct_and_nested_resolution(bool $arrayFactory, bool $nested, bool $forceEager): void
    {
        if (!$forceEager && !self::supportsLazyObjects()) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $available = new ReflectionProperty(DependencyResolver::class, 'lazyObjectsAvailable');
        $previous = $available->getValue();
        if ($forceEager) {
            $available->setValue(null, false);
        }

        try {
            $factory = new class() {
                public int $calls = 0;

                public function __invoke(ContainerInterface $container): ExpensiveReportGenerator
                {
                    ++$this->calls;

                    return new ExpensiveReportGenerator($container->get(ClassWithoutDependencies::class));
                }

                public function build(ContainerInterface $container): ExpensiveReportGenerator
                {
                    return $this($container);
                }
            };
            $container = new Container();
            $container->lazy(ExpensiveReportGenerator::class, $arrayFactory ? [$factory, 'build'] : $factory);
            $generator = $nested
                ? $container->get(OuterHoldingReportGenerator::class)->reportGenerator
                : $container->get(ExpensiveReportGenerator::class);

            self::assertInstanceOf(ExpensiveReportGenerator::class, $generator);
            self::assertSame($forceEager ? 1 : 0, $factory->calls);
            self::assertInstanceOf(ClassWithoutDependencies::class, $generator->dependency);
            self::assertSame(1, $factory->calls);
            self::assertSame(1, ConstructionCounter::countFor(ExpensiveReportGenerator::class));
        } finally {
            if ($forceEager) {
                $available->setValue(null, $previous);
            }
        }
    }

    /**
     * @return iterable<string, array{bool, bool, bool}>
     */
    public static function callableLazyFactoryProvider(): iterable
    {
        foreach ([false, true] as $arrayFactory) {
            foreach ([false, true] as $nested) {
                foreach ([false, true] as $forceEager) {
                    $name = ($arrayFactory ? 'array' : 'invokable')
                        . ($nested ? ' nested' : ' direct')
                        . ($forceEager ? ' eager fallback' : ' native');

                    yield $name => [$arrayFactory, $nested, $forceEager];
                }
            }
        }
    }

    private static function supportsLazyObjects(): bool
    {
        return method_exists(ReflectionClass::class, 'newLazyGhost');
    }
}
