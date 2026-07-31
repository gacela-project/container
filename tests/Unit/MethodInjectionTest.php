<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\CompilationSkipReason;
use Gacela\Container\Container;
use Gacela\Container\Exception\DependencyInvalidArgumentException;
use GacelaTest\Fake\ChildInheritingAnInjectedMethod;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\LazyServiceWithInjectedMethod;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\ServiceOrderingConstructorPropertyAndMethod;
use GacelaTest\Fake\ServiceWhoseSetterDerivesState;
use GacelaTest\Fake\ServiceWithInjectedMethod;
use GacelaTest\Fake\ServiceWithInjectedMethodNamingAnImplementation;
use GacelaTest\Fake\ServiceWithInjectedMethodTakingAScalar;
use GacelaTest\Fake\ServiceWithPrivateInjectedMethod;
use GacelaTest\Fake\ServiceWithStaticInjectedMethod;
use GacelaTest\Fake\ServiceWithTwoInjectedMethods;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class MethodInjectionTest extends TestCase
{
    protected function setUp(): void
    {
        Container::resetStaticCaches();
    }

    protected function tearDown(): void
    {
        Container::resetStaticCaches();
    }

    public function test_a_setter_is_called_with_its_dependency(): void
    {
        $service = (new Container())->get(ServiceWithInjectedMethod::class);

        self::assertInstanceOf(ClassWithoutDependencies::class, $service->dependency);
    }

    public function test_a_setter_is_called_exactly_once(): void
    {
        $service = (new Container())->get(ServiceWithInjectedMethod::class);

        self::assertSame(1, $service->calls);
    }

    /**
     * Order is observable, so it is fixed and documented rather than left to
     * whatever reflection happens to return.
     */
    public function test_setters_run_in_declaration_order(): void
    {
        $service = (new Container())->get(ServiceWithTwoInjectedMethods::class);

        self::assertSame(['first', 'second'], $service->order);
    }

    public function test_a_setter_runs_after_property_injection(): void
    {
        $service = (new Container())->get(ServiceOrderingConstructorPropertyAndMethod::class);

        self::assertTrue($service->propertyWasSetFirst);
    }

    /**
     * The thing property injection cannot do: a setter can derive state rather
     * than only take the value.
     */
    public function test_a_setter_may_derive_state_from_what_it_is_given(): void
    {
        $container = new Container([
            RepositoryInterface::class => DatabaseRepository::class,
        ]);

        $service = $container->get(ServiceWhoseSetterDerivesState::class);

        self::assertSame(DatabaseRepository::class, $service->describedBy);
    }

    public function test_a_setter_parameter_honours_its_own_inject_attribute(): void
    {
        $service = (new Container())->get(ServiceWithInjectedMethodNamingAnImplementation::class);

        self::assertInstanceOf(DatabaseRepository::class, $service->repository);
    }

    public function test_a_setter_parameter_honours_a_contextual_binding(): void
    {
        $container = new Container();
        $container->when(ServiceWhoseSetterDerivesState::class)
            ->needs(RepositoryInterface::class)
            ->give(DatabaseRepository::class);

        $service = $container->get(ServiceWhoseSetterDerivesState::class);

        self::assertSame(DatabaseRepository::class, $service->describedBy);
    }

    public function test_an_inherited_setter_is_called(): void
    {
        $service = (new Container())->get(ChildInheritingAnInjectedMethod::class);

        self::assertInstanceOf(ClassWithoutDependencies::class, $service->fromParent);
    }

    public function test_a_static_method_is_refused_by_name_rather_than_skipped(): void
    {
        $this->expectException(DependencyInvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is static and cannot be injected/');

        (new Container())->get(ServiceWithStaticInjectedMethod::class);
    }

    public function test_a_non_public_method_is_refused_by_name_rather_than_skipped(): void
    {
        $this->expectException(DependencyInvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is not public and cannot be injected/');

        (new Container())->get(ServiceWithPrivateInjectedMethod::class);
    }

    public function test_an_unresolvable_setter_parameter_throws_like_a_constructor_one(): void
    {
        $this->expectException(DependencyInvalidArgumentException::class);

        (new Container())->get(ServiceWithInjectedMethodTakingAScalar::class);
    }

    /**
     * The calls belong inside the initializer with the constructor: running
     * them at ghost creation would resolve the graph laziness exists to defer.
     */
    public function test_a_lazy_class_defers_its_setters_until_first_touch(): void
    {
        if (!method_exists(ReflectionClass::class, 'newLazyGhost')) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        LazyServiceWithInjectedMethod::$calls = 0;

        $service = (new Container())->get(LazyServiceWithInjectedMethod::class);

        // Read off the class, not the instance: touching the object is exactly
        // what would initialize it and hide the thing being asserted.
        self::assertSame(0, LazyServiceWithInjectedMethod::$calls);

        // Touching it initializes, which is when the setter runs.
        self::assertInstanceOf(ClassWithoutDependencies::class, $service->setterDependency);
        self::assertSame(1, LazyServiceWithInjectedMethod::$calls);
    }

    public function test_the_compiler_refuses_a_class_with_an_injected_method(): void
    {
        $report = (new Container())->compileReport([ServiceWithInjectedMethod::class]);

        self::assertSame(
            CompilationSkipReason::InjectedMethod,
            $report->reasonFor(ServiceWithInjectedMethod::class),
        );
        self::assertStringContainsString(
            'cannot call',
            (string) $report->explain(ServiceWithInjectedMethod::class),
        );
    }

    /**
     * A plan written before method injection existed has no 'methods'. Deriving
     * it on read beats trusting it: the alternative is silently dropping the
     * calls in the one environment a compiled cache is used.
     */
    public function test_a_cache_written_before_method_injection_still_calls_setters(): void
    {
        $plans = (new Container())->compile([ServiceWithInjectedMethod::class]);

        $legacy = [];
        foreach ($plans as $class => $plan) {
            unset($plan['methods']);
            $legacy[$class] = $plan;
        }

        $service = (new Container([], [], $legacy))->get(ServiceWithInjectedMethod::class);

        self::assertInstanceOf(ClassWithoutDependencies::class, $service->dependency);
    }

    public function test_a_class_with_no_injected_methods_is_unaffected(): void
    {
        $service = (new Container())->get(ClassWithoutDependencies::class);

        self::assertInstanceOf(ClassWithoutDependencies::class, $service);
    }
}
