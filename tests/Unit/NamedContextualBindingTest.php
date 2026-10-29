<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use DateTimeImmutable;
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

    public function test_named_binding_preserves_a_string_that_names_a_function(): void
    {
        $container = new Container();
        $container->when(ServiceWithScalarDependency::class)->needs('$apiKey')->give('date');

        self::assertSame('date', $container->get(ServiceWithScalarDependency::class)->apiKey);
        self::assertSame('date', $container->get(ServiceWithScalarDependency::class)->apiKey);
    }

    public function test_named_binding_preserves_a_callable_array(): void
    {
        $value = [DateTimeImmutable::class, 'createFromFormat'];
        self::assertIsCallable($value);
        $container = new Container();
        $container->when(ServiceWithMixedDependency::class)->needs('$value')->give($value);

        self::assertSame($value, $container->get(ServiceWithMixedDependency::class)->value);
    }

    public function test_named_binding_preserves_an_invokable_object(): void
    {
        $value = new class() {
            public function __invoke(): string
            {
                return 'invoked';
            }
        };
        $container = new Container();
        $container->when(ServiceWithMixedDependency::class)->needs('$value')->give($value);

        self::assertSame($value, $container->get(ServiceWithMixedDependency::class)->value);
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
