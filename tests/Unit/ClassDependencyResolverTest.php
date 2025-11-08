<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\DependencyResolver;
use Gacela\Container\Exception\DependencyInvalidArgumentException;
use Gacela\Container\Exception\DependencyNotFoundException;
use GacelaTest\Fake\ClassWithInterfaceDependencies;
use GacelaTest\Fake\ClassWithObjectDependencies;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\PersonInterface;
use GacelaTest\Fake\PersonWithoutDefaultValues;
use GacelaTest\Fake\PersonWithoutParamType;
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
        $resolver = new DependencyResolver([
            PersonInterface::class => Person::class,
        ]);
        $actual = $resolver->resolveDependencies(ClassWithInterfaceDependencies::class);

        $expected = [new Person()];

        self::assertEquals($expected, $actual);
    }

    public function test_use_mapped_interface_dependency(): void
    {
        $person = new Person();
        $person->name = 'anything';

        $resolver = new DependencyResolver([
            PersonInterface::class => $person,
        ]);
        $actual = $resolver->resolveDependencies(ClassWithInterfaceDependencies::class);

        $expected = [$person];

        self::assertSame($expected, $actual);
    }

    public function test_missing_interface_dependency(): void
    {
        $this->expectExceptionObject(DependencyNotFoundException::mapNotFoundForClassName(PersonInterface::class));

        $resolver = new DependencyResolver();
        $resolver->resolveDependencies(ClassWithInterfaceDependencies::class);
    }

    public function test_missing_default_raw_dependency_value(): void
    {
        $this->expectExceptionObject(DependencyInvalidArgumentException::unableToResolve('string', PersonWithoutDefaultValues::class));

        $resolver = new DependencyResolver();
        $resolver->resolveDependencies(PersonWithoutDefaultValues::class);
    }

    public function test_missing_param_types_on_dependency_value(): void
    {
        $this->expectExceptionObject(DependencyInvalidArgumentException::noParameterTypeFor('name'));

        $resolver = new DependencyResolver();
        $resolver->resolveDependencies(PersonWithoutParamType::class);
    }

    public function test_missing_interface_with_suggestions(): void
    {
        $resolver = new DependencyResolver([
            'GacelaTest\\Fake\\PersonInterfaceTypo' => Person::class,
            'GacelaTest\\Fake\\PersonInterfce' => Person::class, // Close typo
        ]);

        try {
            $resolver->resolveDependencies(ClassWithInterfaceDependencies::class);
            self::fail('Expected DependencyNotFoundException to be thrown');
        } catch (DependencyNotFoundException $e) {
            self::assertStringContainsString('Did you mean one of these?', $e->getMessage());
        }
    }
}
