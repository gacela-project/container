<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use ArrayObject;
use Closure;
use Gacela\Container\Container;
use Gacela\Container\Exception\ContainerException;
use GacelaTest\Fake\ClassWithoutDependencies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExtendSchedulingTest extends TestCase
{
    public function test_extending_an_autowirable_class_schedules_instead_of_throwing(): void
    {
        $container = new Container();

        $result = $container->extend(
            ClassWithoutDependencies::class,
            static fn (object $service): object => $service,
        );

        self::assertInstanceOf(Closure::class, $result);
    }

    public function test_a_scheduled_extension_is_applied_when_the_service_is_set(): void
    {
        $container = new Container();

        $container->extend('service', static fn (object $service): object => new ArrayObject(['extended']));
        $container->set('service', new ClassWithoutDependencies());

        self::assertInstanceOf(ArrayObject::class, $container->get('service'));
    }

    public function test_a_scheduled_extension_on_a_class_id_is_applied_when_set(): void
    {
        $container = new Container();

        $container->extend(
            ClassWithoutDependencies::class,
            static fn (object $service): object => new ArrayObject(['extended']),
        );
        $container->set(ClassWithoutDependencies::class, new ClassWithoutDependencies());

        self::assertInstanceOf(ArrayObject::class, $container->get(ClassWithoutDependencies::class));
    }

    public function test_extending_an_unknown_string_id_still_schedules(): void
    {
        $container = new Container();

        $result = $container->extend('not-a-class', static fn (object $s): object => $s);

        self::assertInstanceOf(Closure::class, $result);
    }

    public function test_extending_an_already_registered_instance_applies_immediately(): void
    {
        $container = new Container();
        $container->set('service', new ClassWithoutDependencies());

        $container->extend('service', static fn (object $s): object => new ArrayObject(['now']));

        self::assertInstanceOf(ArrayObject::class, $container->get('service'));
    }

    public function test_is_factory_is_false_for_an_autowirable_class(): void
    {
        $container = new Container();

        self::assertFalse($container->isFactory(ClassWithoutDependencies::class));
    }

    public function test_array_access_isset_keeps_the_widened_has_semantics(): void
    {
        $container = new Container();

        // offsetExists() intentionally uses the public has(): resolvability.
        self::assertTrue(isset($container[ClassWithoutDependencies::class]));
    }

    /**
     * @param callable(Container): void $register
     */
    #[DataProvider('bindingRegistrations')]
    public function test_extending_a_binding_throws(callable $register): void
    {
        $container = new Container();
        $register($container);

        $this->expectException(ContainerException::class);
        $container->extend('service', static fn (object $service): object => $service);
    }

    /**
     * @param callable(Container): void $register
     */
    #[DataProvider('bindingRegistrations')]
    public function test_extending_a_resolved_binding_throws(callable $register): void
    {
        $container = new Container();
        $register($container);
        $container->get('service');

        $this->expectException(ContainerException::class);
        $container->extend('service', static fn (object $service): object => $service);
    }

    /**
     * @return iterable<string, array{callable(Container): void}>
     */
    public static function bindingRegistrations(): iterable
    {
        yield 'class binding' => [static function (Container $container): void {
            $container->bind('service', ClassWithoutDependencies::class);
        }];
        yield 'closure binding' => [static function (Container $container): void {
            $container->bind('service', static fn (): object => new ClassWithoutDependencies());
        }];
        yield 'object binding' => [static function (Container $container): void {
            $container->bind('service', new ClassWithoutDependencies());
        }];
        yield 'class singleton' => [static function (Container $container): void {
            $container->singleton('service', ClassWithoutDependencies::class);
        }];
        yield 'closure singleton' => [static function (Container $container): void {
            $container->singleton('service', static fn (): object => new ClassWithoutDependencies());
        }];
        yield 'object singleton' => [static function (Container $container): void {
            $container->singleton('service', new ClassWithoutDependencies());
        }];
    }

    public function test_extending_an_alias_to_a_binding_throws(): void
    {
        $container = new Container();
        $container->bind('service', ClassWithoutDependencies::class);
        $container->alias('alias', 'service');

        $this->expectException(ContainerException::class);
        $container->extend('alias', static fn (object $service): object => $service);
    }

    public function test_extending_a_local_binding_in_a_scope_throws(): void
    {
        $container = new Container();
        $scope = $container->createScope();
        $scope->bind('service', ClassWithoutDependencies::class);

        $this->expectException(ContainerException::class);
        $scope->extend('service', static fn (object $service): object => $service);
    }

    public function test_extending_a_stored_instance_that_shadows_a_binding_still_applies(): void
    {
        $container = new Container();
        $container->bind('service', ClassWithoutDependencies::class);
        $container->set('service', new ClassWithoutDependencies());
        $container->extend('service', static fn (object $service): object => new ArrayObject(['extended']));

        self::assertEquals(new ArrayObject(['extended']), $container->get('service'));
    }
}
