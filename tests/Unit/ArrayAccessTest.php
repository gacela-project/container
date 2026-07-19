<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use GacelaTest\Fake\ClassWithoutDependencies;
use PHPUnit\Framework\TestCase;

final class ArrayAccessTest extends TestCase
{
    public function test_offset_get_maps_to_get(): void
    {
        $container = new Container();

        self::assertInstanceOf(ClassWithoutDependencies::class, $container[ClassWithoutDependencies::class]);
    }

    public function test_offset_set_get_and_exists(): void
    {
        $container = new Container();
        $service = new ClassWithoutDependencies();

        $container['service'] = $service;

        self::assertTrue(isset($container['service']));
        self::assertTrue($container->has('service'));
        self::assertSame($service, $container['service']);
    }

    public function test_offset_unset_maps_to_remove(): void
    {
        $container = new Container();
        $container['service'] = new ClassWithoutDependencies();

        unset($container['service']);

        self::assertFalse(isset($container['service']));
    }

    public function test_offset_exists_is_false_for_unknown_id(): void
    {
        $container = new Container();

        self::assertFalse(isset($container['nothing']));
    }
}
