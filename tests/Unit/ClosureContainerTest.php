<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use GacelaTest\Fake\ClassWithInterfaceDependencies;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\ClassWithRelationship;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\PersonInterface;
use PHPUnit\Framework\TestCase;

final class ClosureContainerTest extends TestCase
{
    public function test_static_create_without_dependencies(): void
    {
        $actual = Container::resolveClosure(static fn () => '');

        self::assertSame('', $actual);
    }

    public function test_static_resolve_callable_with_inner_dependencies_without_dependencies(): void
    {
        $actual = Container::resolveClosure(
            static fn (ClassWithoutDependencies $object) => serialize($object),
        );

        self::assertEquals(
            new ClassWithoutDependencies(),
            unserialize($actual),
        );
    }

    public function test_static_resolve_callable_with_inner_dependencies_with_many_dependencies(): void
    {
        $actual = Container::resolveClosure(
            static fn (ClassWithRelationship $object) => serialize($object),
        );

        self::assertEquals(
            new ClassWithRelationship(new Person(), new Person()),
            unserialize($actual),
        );
    }

    public function test_use_mapped_interface_dependency(): void
    {
        $container = new Container([
            PersonInterface::class => Person::class,
        ]);

        $actual = $container->resolve(
            static fn (ClassWithInterfaceDependencies $object) => serialize($object),
        );

        self::assertEquals(
            new ClassWithInterfaceDependencies(new Person()),
            unserialize($actual),
        );
    }

    public function test_resolve_object_from_callable(): void
    {
        $person = new Person();
        $person->name = 'person-name';

        $container = new Container([
            PersonInterface::class => static fn () => $person,
        ]);

        $actual = $container->resolve(
            static fn (PersonInterface $object) => serialize($object),
        );

        self::assertEquals($person, unserialize($actual));
    }
}
