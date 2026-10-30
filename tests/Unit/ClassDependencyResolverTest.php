<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\DependencyResolver;
use Gacela\Container\Exception\DependencyInvalidArgumentException;
use Gacela\Container\Exception\DependencyNotFoundException;
use Gacela\Container\PlanRegistry;
use GacelaTest\Fake\AbstractService;
use GacelaTest\Fake\ClassWithInterfaceDependencies;
use GacelaTest\Fake\ClassWithMissingClassDependency;
use GacelaTest\Fake\ClassWithObjectDependencies;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\OuterHoldingUntypedMiddleService;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\PersonInterface;
use GacelaTest\Fake\PersonWithoutDefaultValues;
use GacelaTest\Fake\PersonWithoutParamType;
use GacelaTest\Fake\ServiceWithUntypedMiddleParameter;
use GacelaTest\Fake\ServiceWithUntypedVariadicParameter;
use PHPUnit\Framework\TestCase;

final class ClassDependencyResolverTest extends TestCase
{
    public function test_without_dependencies(): void
    {
        $resolver = new DependencyResolver();
        $actual = $resolver->resolveDependencies(ClassWithoutDependencies::class);

        self::assertSame([], $actual);
    }

    public function test_object_dependencies(): void
    {
        $resolver = new DependencyResolver();
        $actual = $resolver->resolveDependencies(ClassWithObjectDependencies::class);

        $expected = [new Person()];

        self::assertEquals($expected, $actual);
    }

    public function test_interface_dependency(): void
    {
        $bindings = [
            PersonInterface::class => Person::class,
        ];
        $resolver = new DependencyResolver($bindings);
        $actual = $resolver->resolveDependencies(ClassWithInterfaceDependencies::class);

        $expected = [new Person()];

        self::assertEquals($expected, $actual);
    }

    public function test_use_mapped_interface_dependency(): void
    {
        $person = new Person();
        $person->name = 'anything';

        $bindings = [
            PersonInterface::class => $person,
        ];
        $resolver = new DependencyResolver($bindings);
        $actual = $resolver->resolveDependencies(ClassWithInterfaceDependencies::class);

        $expected = [$person];

        self::assertSame($expected, $actual);
    }

    public function test_missing_interface_dependency(): void
    {
        $this->expectExceptionObject(DependencyNotFoundException::mapNotFoundForClassName(
            PersonInterface::class,
            [],
            'parameter $person of ' . ClassWithInterfaceDependencies::class . '::__construct()',
            [ClassWithInterfaceDependencies::class],
        ));

        $resolver = new DependencyResolver();
        $resolver->resolveDependencies(ClassWithInterfaceDependencies::class);
    }

    public function test_missing_default_raw_dependency_value(): void
    {
        $this->expectExceptionObject(DependencyInvalidArgumentException::unableToResolve('string', PersonWithoutDefaultValues::class, [PersonWithoutDefaultValues::class], 'name'));

        $resolver = new DependencyResolver();
        $resolver->resolveDependencies(PersonWithoutDefaultValues::class);
    }

    public function test_a_parameter_typed_with_a_missing_class_reports_a_missing_dependency(): void
    {
        $this->expectException(DependencyNotFoundException::class);
        $resolver = new DependencyResolver();

        $resolver->resolveDependencies(ClassWithMissingClassDependency::class);
    }

    public function test_missing_param_types_on_dependency_value(): void
    {
        $this->expectExceptionObject(DependencyInvalidArgumentException::noParameterTypeFor('name', [PersonWithoutParamType::class]));

        $resolver = new DependencyResolver();
        $resolver->resolveDependencies(PersonWithoutParamType::class);
    }

    public function test_an_untyped_middle_parameter_keeps_its_default_on_repeated_resolution(): void
    {
        $container = new Container();

        for ($i = 0; $i < 3; ++$i) {
            $service = $container->get(ServiceWithUntypedMiddleParameter::class);

            self::assertInstanceOf(ClassWithoutDependencies::class, $service->first);
            self::assertSame('middle-default', $service->middle);
            self::assertSame('last-default', $service->last);
        }
    }

    public function test_a_nested_untyped_middle_parameter_preserves_argument_positions(): void
    {
        $container = new Container();

        for ($i = 0; $i < 3; ++$i) {
            $service = $container->get(OuterHoldingUntypedMiddleService::class)->inner;

            self::assertInstanceOf(ClassWithoutDependencies::class, $service->first);
            self::assertSame('middle-default', $service->middle);
            self::assertSame('last-default', $service->last);
        }
    }

    public function test_named_bindings_fill_an_untyped_middle_parameter_without_shifting_later_values(): void
    {
        $container = new Container();
        $container->when(ServiceWithUntypedMiddleParameter::class)->needs('$middle')->give('configured-middle');
        $container->when(ServiceWithUntypedMiddleParameter::class)->needs('$last')->give('configured-last');

        for ($i = 0; $i < 3; ++$i) {
            $direct = $container->get(ServiceWithUntypedMiddleParameter::class);
            $nested = $container->get(OuterHoldingUntypedMiddleService::class)->inner;

            self::assertInstanceOf(ClassWithoutDependencies::class, $nested->first);
            self::assertSame('configured-middle', $direct->middle);
            self::assertSame('configured-last', $direct->last);
            self::assertSame('configured-middle', $nested->middle);
            self::assertSame('configured-last', $nested->last);
        }
    }

    public function test_a_nested_required_untyped_parameter_throws_a_container_exception_on_repeated_resolution(): void
    {
        $resolver = new DependencyResolver();
        $exceptions = 0;
        $callable = static fn (PersonWithoutParamType $person): PersonWithoutParamType => $person;

        for ($i = 0; $i < 3; ++$i) {
            try {
                $resolver->resolveCallableDependencies($callable);
            } catch (DependencyInvalidArgumentException) {
                ++$exceptions;
            }
        }

        self::assertSame(3, $exceptions);
    }

    public function test_a_named_binding_supplies_a_nested_required_untyped_parameter(): void
    {
        $container = new Container();
        $container->when(PersonWithoutParamType::class)->needs('$name')->give('configured-name');
        $callable = static fn (PersonWithoutParamType $person): PersonWithoutParamType => $person;

        for ($i = 0; $i < 3; ++$i) {
            self::assertInstanceOf(PersonWithoutParamType::class, $container->resolve($callable));
        }
    }

    public function test_a_nested_untyped_variadic_remains_optional(): void
    {
        $container = new Container();
        $callable = static fn (ServiceWithUntypedVariadicParameter $service): ServiceWithUntypedVariadicParameter => $service;

        for ($i = 0; $i < 3; ++$i) {
            $service = $container->resolve($callable);

            self::assertSame('default-label', $service->label);
            self::assertSame([], $service->values);
        }
    }

    public function test_missing_interface_with_suggestions(): void
    {
        $bindings = [
            'GacelaTest\\Fake\\PersonInterfaceTypo' => Person::class,
            'GacelaTest\\Fake\\PersonInterfce' => Person::class, // Close typo
        ];
        $resolver = new DependencyResolver($bindings);

        try {
            $resolver->resolveDependencies(ClassWithInterfaceDependencies::class);
            self::fail('Expected DependencyNotFoundException to be thrown');
        } catch (DependencyNotFoundException $e) {
            self::assertStringContainsString('Did you mean one of these?', $e->getMessage());
        }
    }

    public function test_instantiability_is_answered_off_the_class_plan(): void
    {
        // The point of the check living here: the plan it consults is the same
        // one resolveDependencies() needs, so asking costs no extra reflection.
        $planRegistry = new PlanRegistry();
        $resolver = new DependencyResolver(planRegistry: $planRegistry);

        self::assertTrue($resolver->isInstantiable(ClassWithoutDependencies::class));
        self::assertArrayHasKey(ClassWithoutDependencies::class, $planRegistry->plans);
    }

    public function test_an_abstract_class_is_not_instantiable(): void
    {
        self::assertFalse((new DependencyResolver())->isInstantiable(AbstractService::class));
    }

    public function test_an_undefined_class_is_not_instantiable(): void
    {
        self::assertFalse((new DependencyResolver())->isInstantiable('GacelaTest\\Fake\\NeverDefined'));
    }
}
