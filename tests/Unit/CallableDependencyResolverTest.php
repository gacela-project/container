<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\DependencyResolver;
use Gacela\Container\Exception\DependencyInvalidArgumentException;
use Gacela\Container\Exception\DependencyNotFoundException;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\PersonInterface;
use PHPUnit\Framework\TestCase;

final class CallableDependencyResolverTest extends TestCase
{
    public function test_without_dependencies(): void
    {
        $resolver = new DependencyResolver();
        $actual = $resolver->resolveCallableDependencies(static function () {
            return [];
        });

        self::assertSame([], $actual);
    }

    public function test_object_dependencies(): void
    {
        $resolver = new DependencyResolver();
        $actual = $resolver->resolveCallableDependencies(static function (Person $person) {
            return $person;
        });

        $expected = [new Person()];

        self::assertEquals($expected, $actual);
    }

    public function test_interface_dependency(): void
    {
        $bindings = [
            PersonInterface::class => Person::class,
        ];
        $resolver = new DependencyResolver($bindings);
        $actual = $resolver->resolveCallableDependencies(static function (PersonInterface $person) {
            return $person;
        });

        $expected = [new Person()];

        self::assertEquals($expected, $actual);
    }

    public function test_use_mapped_interface_dependency(): void
    {
        $person = new Person();
        $person->name = 'anything';

        $bindings = [
            PersonInterface::class => $person,
        ];
        $resolver = new DependencyResolver($bindings);

        $actual = $resolver->resolveCallableDependencies(static function (PersonInterface $person) {
            return $person;
        });

        $expected = [$person];

        self::assertSame($expected, $actual);
    }

    public function test_missing_interface_dependency(): void
    {
        $this->expectExceptionObject(DependencyNotFoundException::mapNotFoundForClassName(PersonInterface::class));

        $resolver = new DependencyResolver();

        $resolver->resolveCallableDependencies(static function (PersonInterface $person) {
            return $person;
        });
    }

    public function test_missing_default_raw_dependency_value(): void
    {
        $this->expectExceptionObject(DependencyInvalidArgumentException::unableToResolve('string', self::class));

        $resolver = new DependencyResolver();
        $resolver->resolveCallableDependencies(static function (string $name) {
            return $name;
        });
    }

    public function test_missing_param_types_on_dependency_value(): void
    {
        $this->expectExceptionObject(DependencyInvalidArgumentException::noParameterTypeFor('name'));

        $resolver = new DependencyResolver();
        $resolver->resolveCallableDependencies(static function ($name) {
            return $name;
        });
    }
}
