<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use ArrayObject;
use Closure;
use Gacela\Container\Container;
use GacelaTest\Fake\ClassWithoutDependencies;
use PHPUnit\Framework\TestCase;

/**
 * extend() must schedule when nothing is stored under the id yet, including
 * when the id happens to name a class the container could autowire.
 *
 * These two questions are different and were conflated in 1.0.0:
 *   - has($id)  -> will get($id) resolve? (true for any instantiable class)
 *   - stored?   -> is there an instance registered right now?
 */
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
}
