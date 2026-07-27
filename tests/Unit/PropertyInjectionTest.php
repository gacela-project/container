<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\Exception\CircularDependencyException;
use Gacela\Container\Exception\DependencyInvalidArgumentException;
use GacelaTest\Fake\ChildOfInjectedParent;
use GacelaTest\Fake\ClassWithDependencyWithoutDependencies;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\ConstructionCounter;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\FactoryWithInjectedProperty;
use GacelaTest\Fake\LazyWithInjectedProperty;
use GacelaTest\Fake\OuterHoldingInjectedService;
use GacelaTest\Fake\ParentWithPrivateInjectedProperty;
use GacelaTest\Fake\PropertyCycleA;
use GacelaTest\Fake\ServiceWithInitializedInjectedProperty;
use GacelaTest\Fake\ServiceWithInjectedInterfaceProperty;
use GacelaTest\Fake\ServiceWithInjectedProperty;
use GacelaTest\Fake\ServiceWithPromotedInject;
use GacelaTest\Fake\ServiceWithReadonlyInjectedProperty;
use GacelaTest\Fake\ServiceWithScalarInjectedProperty;
use GacelaTest\Fake\ServiceWithUntypedInjectedProperty;
use GacelaTest\Fake\ShadowingChild;
use GacelaTest\Fake\SingletonWithInjectedProperty;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * `#[Inject]` on properties.
 *
 * Constructor injection stays the default; this exists for classes whose
 * constructor is not yours to change. It is deliberately *not* a way around
 * CircularDependencyException, and the cycle test below pins that.
 */
final class PropertyInjectionTest extends TestCase
{
    protected function setUp(): void
    {
        ConstructionCounter::reset();
        ServiceWithInitializedInjectedProperty::$shared = null;
    }

    public function test_it_injects_a_private_typed_property(): void
    {
        $service = (new Container())->get(ServiceWithInjectedProperty::class);

        self::assertInstanceOf(ClassWithoutDependencies::class, $service->dependency());
    }

    public function test_it_injects_the_named_implementation(): void
    {
        $service = (new Container())->get(ServiceWithInjectedInterfaceProperty::class);

        self::assertInstanceOf(DatabaseRepository::class, $service->repository);
    }

    public function test_it_injects_properties_of_a_nested_dependency(): void
    {
        // The nested path goes through instantiateFromPlan(), not the top-level
        // one, so it needs its own proof.
        $outer = (new Container())->get(OuterHoldingInjectedService::class);

        self::assertInstanceOf(ClassWithoutDependencies::class, $outer->inner->dependency());
    }

    public function test_it_injects_inherited_private_and_protected_properties(): void
    {
        $child = (new Container())->get(ChildOfInjectedParent::class);

        self::assertInstanceOf(ClassWithoutDependencies::class, $child->privateDependency());
        self::assertInstanceOf(ClassWithoutDependencies::class, $child->protectedDependency());
        self::assertInstanceOf(ClassWithDependencyWithoutDependencies::class, $child->ownDependency);
    }

    public function test_the_parent_still_resolves_on_its_own(): void
    {
        $parent = (new Container())->get(ParentWithPrivateInjectedProperty::class);

        self::assertInstanceOf(ClassWithoutDependencies::class, $parent->privateDependency());
    }

    public function test_it_overwrites_a_property_that_already_has_a_default(): void
    {
        $service = (new Container())->get(ServiceWithInitializedInjectedProperty::class);

        self::assertInstanceOf(ClassWithoutDependencies::class, $service->dependency);
    }

    public function test_it_ignores_static_properties(): void
    {
        (new Container())->get(ServiceWithInitializedInjectedProperty::class);

        self::assertNull(ServiceWithInitializedInjectedProperty::$shared);
    }

    public function test_a_promoted_parameter_is_injected_once_by_the_constructor(): void
    {
        // The attribute is visible on both the parameter and the promoted
        // property; acting on both would resolve the dependency twice.
        $service = (new Container())->get(ServiceWithPromotedInject::class);

        self::assertInstanceOf(DatabaseRepository::class, $service->repository);
    }

    public function test_a_readonly_property_fails_with_a_clear_message(): void
    {
        $this->expectException(DependencyInvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/readonly/');

        (new Container())->get(ServiceWithReadonlyInjectedProperty::class);
    }

    public function test_an_untyped_property_fails_with_a_clear_message(): void
    {
        $this->expectException(DependencyInvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/No type hint found for property/');

        (new Container())->get(ServiceWithUntypedInjectedProperty::class);
    }

    public function test_a_scalar_property_fails_with_a_clear_message(): void
    {
        $this->expectException(DependencyInvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cannot be auto-resolved/');

        (new Container())->get(ServiceWithScalarInjectedProperty::class);
    }

    public function test_a_property_cycle_is_still_a_circular_dependency(): void
    {
        // The whole reason property injection is contentious. It must not
        // become an escape hatch for the diagnostic this library provides.
        $this->expectException(CircularDependencyException::class);

        (new Container())->get(PropertyCycleA::class);
    }

    public function test_it_works_with_the_singleton_attribute(): void
    {
        $container = new Container();

        $first = $container->get(SingletonWithInjectedProperty::class);
        $second = $container->get(SingletonWithInjectedProperty::class);

        self::assertSame($first, $second);
        self::assertInstanceOf(ClassWithoutDependencies::class, $first->dependency);
    }

    public function test_it_works_with_the_factory_attribute(): void
    {
        $container = new Container();

        $first = $container->get(FactoryWithInjectedProperty::class);
        $second = $container->get(FactoryWithInjectedProperty::class);

        self::assertNotSame($first, $second);
        self::assertInstanceOf(ClassWithoutDependencies::class, $first->dependency);
    }

    public function test_a_lazy_class_injects_on_first_touch_and_not_before(): void
    {
        if (!method_exists(ReflectionClass::class, 'newLazyGhost')) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $service = (new Container())->get(LazyWithInjectedProperty::class);

        self::assertSame(0, ConstructionCounter::countFor(LazyWithInjectedProperty::class));

        self::assertInstanceOf(ClassWithoutDependencies::class, $service->injected);
        self::assertSame(1, ConstructionCounter::countFor(LazyWithInjectedProperty::class));
    }

    public function test_make_injects_properties_too(): void
    {
        $service = (new Container())->make(ServiceWithInjectedProperty::class);

        self::assertInstanceOf(ClassWithoutDependencies::class, $service->dependency());
    }

    public function test_a_class_without_injected_properties_is_untouched(): void
    {
        $service = (new Container())->get(ClassWithDependencyWithoutDependencies::class);

        self::assertInstanceOf(ClassWithDependencyWithoutDependencies::class, $service);
    }

    public function test_a_private_property_shadowing_the_parent_is_a_separate_slot(): void
    {
        // Two distinct properties that share a name. Keying anything by name
        // alone — the plan, or the cached reflection handle — collapses them.
        $child = (new Container())->get(ShadowingChild::class);

        self::assertInstanceOf(ClassWithoutDependencies::class, $child->parentShadowed());
        self::assertInstanceOf(ClassWithDependencyWithoutDependencies::class, $child->childShadowed());
    }

    public function test_a_failed_injection_does_not_leak_into_the_next_resolution(): void
    {
        $container = new Container();

        try {
            $container->get(PropertyCycleA::class);
        } catch (CircularDependencyException) {
            // Expected; the point is what the container looks like afterwards.
        }

        try {
            $container->get(ServiceWithScalarInjectedProperty::class);
            self::fail('Expected the scalar property to be unresolvable');
        } catch (DependencyInvalidArgumentException $exception) {
            self::assertStringNotContainsString('PropertyCycle', $exception->getMessage());
        }
    }

    public function test_a_cache_written_before_property_injection_still_injects(): void
    {
        // Plans persisted by 1.1.x have no property entry. Trusting that as
        // "nothing to inject" would silently disable the feature in production,
        // which is the only place a compiled cache is used.
        // Resolved as a nested dependency on purpose: that is the path served
        // straight from the stored plan.
        $plans = (new Container())->compile([OuterHoldingInjectedService::class]);

        $legacy = [];
        foreach ($plans as $class => $plan) {
            unset($plan['props']);
            $legacy[$class] = $plan;
        }

        $outer = (new Container([], [], $legacy))->get(OuterHoldingInjectedService::class);

        self::assertInstanceOf(ClassWithoutDependencies::class, $outer->inner->dependency());
    }
}
