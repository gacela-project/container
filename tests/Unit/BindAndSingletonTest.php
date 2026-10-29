<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use ArrayObject;
use Gacela\Container\Container;
use Gacela\Container\ContainerInterface;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\PersonInterface;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\ServiceWithRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BindAndSingletonTest extends TestCase
{
    public function test_bind_registers_an_abstract_to_a_concrete_after_construction(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, DatabaseRepository::class);

        $actual = $container->get(RepositoryInterface::class);

        self::assertInstanceOf(DatabaseRepository::class, $actual);
        self::assertArrayHasKey(RepositoryInterface::class, $container->getBindings());
    }

    public function test_bind_with_a_closure(): void
    {
        $container = new Container();
        $container->bind(PersonInterface::class, static fn (): Person => new Person('Jane'));

        $actual = $container->get(PersonInterface::class);

        self::assertInstanceOf(Person::class, $actual);
        self::assertSame('Jane', $actual->name);
    }

    public function test_singleton_class_returns_the_same_instance(): void
    {
        $container = new Container();
        $container->singleton(ClassWithoutDependencies::class);

        $first = $container->get(ClassWithoutDependencies::class);
        $second = $container->get(ClassWithoutDependencies::class);

        self::assertSame($first, $second);
    }

    public function test_non_singleton_class_returns_fresh_instances(): void
    {
        $container = new Container();

        $first = $container->get(ClassWithoutDependencies::class);
        $second = $container->get(ClassWithoutDependencies::class);

        self::assertNotSame($first, $second);
    }

    public function test_singleton_binds_abstract_to_concrete_and_reuses_instance(): void
    {
        $container = new Container();
        $container->singleton(RepositoryInterface::class, DatabaseRepository::class);

        $first = $container->get(RepositoryInterface::class);
        $second = $container->get(RepositoryInterface::class);

        self::assertInstanceOf(DatabaseRepository::class, $first);
        self::assertSame($first, $second);
    }

    public function test_singleton_with_a_closure_is_memoized(): void
    {
        $container = new Container();
        $calls = 0;
        $container->singleton(PersonInterface::class, static function () use (&$calls): Person {
            ++$calls;
            return new Person('memoized');
        });

        $first = $container->get(PersonInterface::class);
        $second = $container->get(PersonInterface::class);

        self::assertSame($first, $second);
        self::assertSame(1, $calls);
    }

    public function test_a_binding_registered_after_the_first_resolution_is_still_used(): void
    {
        $container = new Container();
        $container->get(ClassWithoutDependencies::class);

        $container->bind(RepositoryInterface::class, DatabaseRepository::class);

        self::assertInstanceOf(
            DatabaseRepository::class,
            $container->make(ServiceWithRepository::class)->repository,
        );
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function bindingResults(): iterable
    {
        yield 'integer' => [3];
        yield 'zero' => [0];
        yield 'false' => [false];
        yield 'true' => [true];
        yield 'float' => [1.25];
        yield 'string' => ['pgsql'];
        yield 'empty string' => [''];
        yield 'class-name string' => [ArrayObject::class];
        yield 'array' => [['driver' => 'pgsql']];
        yield 'null' => [null];
    }

    #[DataProvider('bindingResults')]
    public function test_a_binding_returns_the_factory_result_unchanged(mixed $value): void
    {
        $container = new Container();
        $calls = 0;
        $container->bind('value', static function () use ($value, &$calls): mixed {
            ++$calls;
            return $value;
        });

        self::assertTrue($container->has('value'));
        self::assertSame(0, $calls);
        self::assertSame($value, $container->get('value'));
        self::assertSame($value, $container->get('value'));
        self::assertSame(2, $calls);
    }

    #[DataProvider('bindingResults')]
    public function test_a_singleton_returns_and_memoizes_the_factory_result_unchanged(mixed $value): void
    {
        $container = new Container();
        $calls = 0;
        $container->singleton('value', static function () use ($value, &$calls): mixed {
            ++$calls;
            return $value;
        });

        self::assertTrue($container->has('value'));
        self::assertSame($value, $container->get('value'));
        self::assertSame($value, $container->get('value'));
        self::assertSame(1, $calls);
    }

    public function test_a_null_factory_result_does_not_autowire_the_requested_class(): void
    {
        $container = new Container();
        $container->bind(ArrayObject::class, static fn (): mixed => null);

        self::assertTrue($container->has(ArrayObject::class));
        self::assertNull($container->get(ArrayObject::class));
    }

    public function test_a_binding_factory_result_survives_aliases_and_scope_delegation(): void
    {
        $container = new Container();
        $container->bind('value', static fn (): string => ArrayObject::class);
        $container->alias('alias', 'value');
        $scope = $container->createScope();

        self::assertSame(ArrayObject::class, $scope->get('alias'));
        $scope->bind('value', static fn (): mixed => null);
        self::assertNull($scope->get('alias'));
        self::assertSame(ArrayObject::class, $container->get('value'));
    }

    public function test_a_callable_array_factory_returns_its_class_name_value_unchanged(): void
    {
        $container = new Container();
        $container->set('literal', ArrayObject::class);
        $container->bind('value', [self::class, 'literalFromContainer']);

        self::assertSame(ArrayObject::class, $container->getOrFail('value'));
    }

    public static function literalFromContainer(ContainerInterface $container): mixed
    {
        return $container->get('literal');
    }

}
