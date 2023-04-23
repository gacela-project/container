<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Resolver\Container;
use GacelaTest\Fake\ClassWithInterfaceDependencies;
use GacelaTest\Fake\ClassWithObjectDependencies;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\PersonInterface;
use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    public function test_static_create_without_dependencies(): void
    {
        $actual = Container::create(ClassWithoutDependencies::class);

        self::assertEquals(new ClassWithoutDependencies(), $actual);
    }

    public function test_static_create_with_dependencies(): void
    {
        $actual = Container::create(ClassWithObjectDependencies::class);

        self::assertEquals(new ClassWithObjectDependencies(new Person()), $actual);
    }

    public function test_without_dependencies(): void
    {
        $container = new Container();
        $actual = $container->get(ClassWithoutDependencies::class);

        self::assertEquals(new ClassWithoutDependencies(), $actual);
    }

    public function test_object_with_resolvable_dependencies(): void
    {
        $container = new Container();
        $actual = $container->get(ClassWithObjectDependencies::class);

        self::assertEquals(new ClassWithObjectDependencies(new Person()), $actual);
    }

    public function test_interface_dependency(): void
    {
        $container = new Container([
            PersonInterface::class => Person::class,
        ]);
        $actual = $container->get(ClassWithObjectDependencies::class);

        self::assertEquals(new ClassWithObjectDependencies(new Person()), $actual);
    }

    public function test_use_mapped_interface_dependency(): void
    {
        $person = new Person();
        $person->name = 'anything';

        $container = new Container([
            PersonInterface::class => $person,
        ]);
        $actual = $container->get(ClassWithInterfaceDependencies::class);

        self::assertEquals(new ClassWithInterfaceDependencies($person), $actual);
    }

    public function test_has_not_existing_class(): void
    {
        $container = new Container();
        $actual = $container->has(InexistentClass::class);

        self::assertFalse($actual);
    }

    public function test_has_existing_class(): void
    {
        $container = new Container();
        $actual = $container->has(Person::class);

        self::assertTrue($actual);
    }
}
