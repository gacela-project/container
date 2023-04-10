<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\DependencyResolver\DependencyResolver;
use GacelaTest\Fake\ClassWithInterfaceDependencies;
use GacelaTest\Fake\ClassWithObjectDependencies;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\PersonInterface;
use PHPUnit\Framework\TestCase;

final class DependencyResolverTest extends TestCase
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
}
