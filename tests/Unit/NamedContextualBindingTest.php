<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\Exception\DependencyInvalidArgumentException;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\ServiceWithMixedDependency;
use GacelaTest\Fake\ServiceWithScalarDependency;
use PHPUnit\Framework\TestCase;

final class NamedContextualBindingTest extends TestCase
{
    public function test_named_binding_supplies_a_scalar_without_default(): void
    {
        $container = new Container();
        $container->when(ServiceWithScalarDependency::class)
            ->needs('$apiKey')
            ->give('secret');

        $service = $container->get(ServiceWithScalarDependency::class);

        self::assertSame('secret', $service->apiKey);
        self::assertInstanceOf(Person::class, $service->person);
    }

    public function test_named_binding_accepts_a_closure_invoked_per_resolution(): void
    {
        $container = new Container();
        $calls = 0;
        $container->when(ServiceWithScalarDependency::class)
            ->needs('$apiKey')
            ->give(static function () use (&$calls): string {
                ++$calls;
                return 'lazy-' . $calls;
            });

        $first = $container->get(ServiceWithScalarDependency::class);
        $second = $container->get(ServiceWithScalarDependency::class);

        self::assertSame('lazy-1', $first->apiKey);
        self::assertSame('lazy-2', $second->apiKey);
    }

    public function test_named_binding_accepts_an_object_value(): void
    {
        $container = new Container();
        $object = new ClassWithoutDependencies();
        $container->when(ServiceWithMixedDependency::class)
            ->needs('$value')
            ->give($object);

        $service = $container->get(ServiceWithMixedDependency::class);

        self::assertSame($object, $service->value);
    }

    public function test_named_binding_accepts_an_array_value(): void
    {
        $container = new Container();
        $container->when(ServiceWithMixedDependency::class)
            ->needs('$value')
            ->give(['a' => 1, 'b' => 2]);

        $service = $container->get(ServiceWithMixedDependency::class);

        self::assertSame(['a' => 1, 'b' => 2], $service->value);
    }

    public function test_named_binding_is_scoped_to_the_declaring_class(): void
    {
        $container = new Container();
        // Bound for a different class only.
        $container->when(ClassWithoutDependencies::class)
            ->needs('$apiKey')
            ->give('other');

        $this->expectException(DependencyInvalidArgumentException::class);

        $container->get(ServiceWithScalarDependency::class);
    }
}
