<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use ArrayObject;
use Gacela\Container\Container;
use Gacela\Container\Exception\ContainerException;
use GacelaTest\Fake\ClassWithDependencyWithoutDependencies;
use GacelaTest\Fake\ClassWithInterfaceDependencies;
use GacelaTest\Fake\ClassWithObjectDependencies;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\ClassWithRelationship;
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

    public function test_static_create_class_with_inner_dependencies_without_dependencies(): void
    {
        $actual = Container::create(ClassWithDependencyWithoutDependencies::class);

        self::assertEquals(new ClassWithDependencyWithoutDependencies(new ClassWithoutDependencies()), $actual);
    }

    public function test_static_create_with_dependencies(): void
    {
        $actual = Container::create(ClassWithObjectDependencies::class);

        self::assertEquals(new ClassWithObjectDependencies(new Person()), $actual);
    }

    public function test_static_create_with_many_dependencies(): void
    {
        $actual = Container::create(ClassWithRelationship::class);

        self::assertEquals(new ClassWithRelationship(new Person(), new Person()), $actual);
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

    public function test_resolve_object_from_interface(): void
    {
        $person = new Person();
        $person->name = 'person-name';

        $container = new Container([
            PersonInterface::class => $person,
        ]);
        $resolvedPerson = $container->get(PersonInterface::class);

        self::assertSame($resolvedPerson, $person);
    }

    public function test_resolve_new_object(): void
    {
        // We are registering 'PersonInterface::class', but 'Person::class' was not.
        // As result, a 'new Person()' will be resolved.
        $person = new Person();
        $person->name = 'person-name';

        $container = new Container([
            PersonInterface::class => $person,
        ]);
        $resolvedPerson = $container->get(Person::class);

        self::assertEquals($resolvedPerson, new Person()); // different objects!
    }

    public function test_interface_not_registered_returns_null(): void
    {
        $container = new Container([
            Person::class => new Person(),
        ]);
        $resolvedPerson = $container->get(PersonInterface::class);

        self::assertNull($resolvedPerson);
    }

    public function test_resolve_object_from_classname(): void
    {
        $container = new Container([
            PersonInterface::class => Person::class,
        ]);
        $resolvedPerson = $container->get(PersonInterface::class);

        self::assertEquals($resolvedPerson, new Person());
    }

    public function test_resolve_object_from_instance_in_a_callable(): void
    {
        $person = new Person();
        $person->name = 'person-name';

        $container = new Container([
            PersonInterface::class => static fn () => $person,
        ]);
        $resolvedPerson = $container->get(PersonInterface::class);

        self::assertEquals($resolvedPerson, $person);
    }

    public function test_resolve_object_from_callable_classname_in_a_callable(): void
    {
        $container = new Container([
            PersonInterface::class => static fn () => Person::class,
        ]);
        $resolvedPerson = $container->get(PersonInterface::class);

        self::assertEquals($resolvedPerson, new Person());
    }

    public function test_get_non_existing_service(): void
    {
        $container = new Container();

        self::assertNull($container->get('unknown-service_name'));
    }

    public function test_has_service(): void
    {
        $container = new Container();
        $container->set('service_name', 'value');

        self::assertTrue($container->has('service_name'));
        self::assertFalse($container->has('unknown-service_name'));
    }

    public function test_remove_existing_service(): void
    {
        $container = new Container();
        $container->set('service_name', 'value');
        $container->remove('service_name');

        self::assertNull($container->get('service_name'));
    }

    public function test_resolve_service_as_raw_string(): void
    {
        $container = new Container();
        $container->set('service_name', 'value');

        $resolvedService = $container->get('service_name');
        self::assertSame('value', $resolvedService);

        $cachedResolvedService = $container->get('service_name');
        self::assertSame('value', $cachedResolvedService);
    }

    public function test_resolve_service_as_function(): void
    {
        $container = new Container();
        $container->set('service_name', static fn () => 'value');

        $resolvedService = $container->get('service_name');
        self::assertSame('value', $resolvedService);

        $cachedResolvedService = $container->get('service_name');
        self::assertSame('value', $cachedResolvedService);
    }

    public function test_resolve_service_as_callable_class(): void
    {
        $container = new Container();
        $container->set(
            'service_name',
            new class() {
                public function __invoke(): string
                {
                    return 'value';
                }
            },
        );

        $resolvedService = $container->get('service_name');
        self::assertSame('value', $resolvedService);

        $cachedResolvedService = $container->get('service_name');
        self::assertSame('value', $cachedResolvedService);
    }

    public function test_resolve_non_factory_service_with_random(): void
    {
        $container = new Container();
        $container->set(
            'service_name',
            static fn () => 'value_' . random_int(0, PHP_INT_MAX),
        );

        self::assertSame(
            $container->get('service_name'),
            $container->get('service_name'),
        );
    }

    public function test_resolve_factory_service_with_random(): void
    {
        $container = new Container();
        $container->set(
            'service_name',
            $container->factory(
                static fn () => 'value_' . random_int(0, PHP_INT_MAX),
            ),
        );

        self::assertNotSame(
            $container->get('service_name'),
            $container->get('service_name'),
        );
    }

    public function test_extend_existing_callable_service(): void
    {
        $container = new Container();
        $container->set('n3', 3);
        $container->set('service_name', static fn () => new ArrayObject([1, 2]));

        $container->extend(
            'service_name',
            static function (ArrayObject $arrayObject, Container $container) {
                $arrayObject->append($container->get('n3'));
                return $arrayObject;
            },
        );

        $container->extend(
            'service_name',
            static fn (ArrayObject $arrayObject) => $arrayObject->append(4),
        );

        /** @var ArrayObject $actual */
        $actual = $container->get('service_name');

        self::assertEquals(new ArrayObject([1, 2, 3, 4]), $actual);
    }

    public function test_extend_existing_object_service(): void
    {
        $container = new Container();
        $container->set('n3', 3);
        $container->set('service_name', new ArrayObject([1, 2]));

        $container->extend(
            'service_name',
            static function (ArrayObject $arrayObject, Container $container) {
                $arrayObject->append($container->get('n3'));
                return $arrayObject;
            },
        );

        $container->extend(
            'service_name',
            static function (ArrayObject $arrayObject): void {
                $arrayObject->append(4);
            },
        );

        /** @var ArrayObject $actual */
        $actual = $container->get('service_name');

        self::assertEquals(new ArrayObject([1, 2, 3, 4]), $actual);
    }

    public function test_extend_existing_array_service(): void
    {
        $container = new Container();
        $container->set('service_name', [1, 2]);

        $container->extend(
            'service_name',
            static function (array $arrayObject): array {
                $arrayObject[] = 3;
                return $arrayObject;
            },
        );

        $container->extend(
            'service_name',
            static function (array &$arrayObject): void {
                $arrayObject[] = 4;
            },
        );

        /** @var ArrayObject $actual */
        $actual = $container->get('service_name');

        self::assertEquals([1, 2, 3, 4], $actual);
    }

    public function test_extend_non_existing_service(): void
    {
        $container = new Container();
        $container->extend('service_name', static fn () => '');

        self::assertNull($container->get('service_name'));
    }

    public function test_service_not_extendable(): void
    {
        $container = new Container();
        $container->set('service_name', 'raw string');

        $this->expectExceptionObject(ContainerException::instanceNotExtendable());
        $container->extend('service_name', static fn (string $str) => $str);
    }

    public function test_extend_existing_used_object_service_is_allowed(): void
    {
        $container = new Container();
        $container->set('service_name', new ArrayObject([1, 2]));
        $container->get('service_name'); // and get frozen

        $this->expectExceptionObject(ContainerException::frozenInstanceExtend('service_name'));

        $container->extend(
            'service_name',
            static fn (ArrayObject $arrayObject) => $arrayObject->append(3),
        );
    }

    public function test_extend_existing_used_callable_service_then_error(): void
    {
        $container = new Container();
        $container->set('service_name', static fn () => new ArrayObject([1, 2]));
        $container->get('service_name'); // and get frozen

        $this->expectExceptionObject(ContainerException::frozenInstanceExtend('service_name'));

        $container->extend(
            'service_name',
            static fn (ArrayObject $arrayObject) => $arrayObject->append(3),
        );
    }

    public function test_extend_later_existing_frozen_object_service_then_error(): void
    {
        $container = new Container();
        $container->extend(
            'service_name',
            static fn (ArrayObject $arrayObject) => $arrayObject->append(3),
        );

        $container->set('service_name', new ArrayObject([1, 2]));
        $container->get('service_name'); // and get frozen

        $this->expectExceptionObject(ContainerException::frozenInstanceExtend('service_name'));

        $container->extend(
            'service_name',
            static fn (ArrayObject $arrayObject) => $arrayObject->append(4),
        );
    }

    public function test_extend_later_existing_frozen_callable_service_then_error(): void
    {
        $container = new Container();
        $container->extend(
            'service_name',
            static fn (ArrayObject $arrayObject) => $arrayObject->append(3),
        );

        $container->set('service_name', static fn () => new ArrayObject([1, 2]));
        $container->get('service_name'); // and get frozen

        $this->expectExceptionObject(ContainerException::frozenInstanceExtend('service_name'));

        $container->extend(
            'service_name',
            static fn (ArrayObject $arrayObject) => $arrayObject->append(4),
        );
    }

    public function test_set_existing_frozen_service(): void
    {
        $container = new Container();
        $container->set('service_name', static fn () => new ArrayObject([1, 2]));
        $container->get('service_name'); // and get frozen

        $this->expectExceptionObject(ContainerException::frozenInstanceOverride('service_name'));
        $container->set('service_name', static fn () => new ArrayObject([3]));
    }

    public function test_protect_service_is_not_resolved(): void
    {
        $container = new Container();
        $service = static fn () => 'value';
        $container->set('service_name', $container->protect($service));

        self::assertSame($service, $container->get('service_name'));
    }

    public function test_protect_service_cannot_be_extended(): void
    {
        $container = new Container();
        $container->set(
            'service_name',
            $container->protect(static fn () => new ArrayObject([1, 2])),
        );

        $this->expectExceptionObject(ContainerException::instanceProtected('service_name'));

        $container->extend(
            'service_name',
            static fn (ArrayObject $arrayObject) => $arrayObject,
        );
    }
}
