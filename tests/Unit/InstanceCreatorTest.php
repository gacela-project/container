<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Resolver\InstanceCreator;
use GacelaTest\Fake\ClassWithInterfaceDependencies;
use GacelaTest\Fake\ClassWithObjectDependencies;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\PersonInterface;
use PHPUnit\Framework\TestCase;

final class InstanceCreatorTest extends TestCase
{
    public function test_static_create_without_dependencies(): void
    {
        $actual = InstanceCreator::create(ClassWithoutDependencies::class);

        self::assertEquals(new ClassWithoutDependencies(), $actual);
    }

    public function test_static_create_with_dependencies(): void
    {
        $actual = InstanceCreator::create(ClassWithObjectDependencies::class);

        self::assertEquals(new ClassWithObjectDependencies(new Person()), $actual);
    }

    public function test_without_dependencies(): void
    {
        $resolver = new InstanceCreator();
        $actual = $resolver->get(ClassWithoutDependencies::class);

        self::assertEquals(new ClassWithoutDependencies(), $actual);
    }

    public function test_object_with_resolvable_dependencies(): void
    {
        $resolver = new InstanceCreator();
        $actual = $resolver->get(ClassWithObjectDependencies::class);

        self::assertEquals(new ClassWithObjectDependencies(new Person()), $actual);
    }

    public function test_interface_dependency(): void
    {
        $resolver = new InstanceCreator([
            PersonInterface::class => Person::class,
        ]);
        $actual = $resolver->get(ClassWithObjectDependencies::class);

        self::assertEquals(new ClassWithObjectDependencies(new Person()), $actual);
    }

    public function test_use_mapped_interface_dependency(): void
    {
        $person = new Person();
        $person->name = 'anything';

        $resolver = new InstanceCreator([
            PersonInterface::class => $person,
        ]);
        $actual = $resolver->get(ClassWithInterfaceDependencies::class);

        self::assertEquals(new ClassWithInterfaceDependencies($person), $actual);
    }

    public function test_has_not_existing_class(): void
    {
        $resolver = new InstanceCreator();
        $actual = $resolver->has(InexistentClass::class);

        self::assertFalse($actual);
    }

    public function test_has_existing_class(): void
    {
        $resolver = new InstanceCreator();
        $actual = $resolver->has(Person::class);

        self::assertTrue($actual);
    }
}
