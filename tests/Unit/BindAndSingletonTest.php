<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\PersonInterface;
use GacelaTest\Fake\RepositoryInterface;
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
}
