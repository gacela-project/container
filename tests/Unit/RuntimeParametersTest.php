<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\Exception\DependencyInvalidArgumentException;
use GacelaTest\Fake\ClassWithObjectDependencies;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\PersonInterface;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\ServiceWithRepository;
use GacelaTest\Fake\ServiceWithScalarDependency;
use PHPUnit\Framework\TestCase;

final class RuntimeParametersTest extends TestCase
{
    public function test_make_supplies_a_scalar_parameter_without_default(): void
    {
        $container = new Container();

        $service = $container->make(ServiceWithScalarDependency::class, ['apiKey' => 'xyz']);

        self::assertInstanceOf(ServiceWithScalarDependency::class, $service);
        self::assertSame('xyz', $service->apiKey);
        self::assertInstanceOf(Person::class, $service->person);
    }

    public function test_make_overrides_an_autowired_dependency_with_an_instance(): void
    {
        $container = new Container();
        $repository = new class() implements RepositoryInterface {};

        $service = $container->make(ServiceWithRepository::class, ['repository' => $repository]);

        self::assertSame($repository, $service->repository);
    }

    public function test_make_without_parameters_still_autowires(): void
    {
        $container = new Container();

        $service = $container->make(ClassWithObjectDependencies::class);

        self::assertInstanceOf(Person::class, $service->person);
    }

    public function test_overrides_do_not_leak_into_later_resolutions(): void
    {
        $container = new Container();

        $first = $container->make(ServiceWithScalarDependency::class, ['apiKey' => 'aaa']);
        $second = $container->make(ServiceWithScalarDependency::class, ['apiKey' => 'bbb']);

        self::assertSame('aaa', $first->apiKey);
        self::assertSame('bbb', $second->apiKey);

        // A different class is unaffected by earlier overrides.
        $other = $container->get(ClassWithObjectDependencies::class);
        self::assertInstanceOf(ClassWithObjectDependencies::class, $other);
    }

    public function test_resolve_supplies_named_parameter(): void
    {
        $container = new Container();

        $result = $container->resolve(
            static fn (string $greeting): string => strtoupper($greeting),
            ['greeting' => 'hi'],
        );

        self::assertSame('HI', $result);
    }

    public function test_make_with_parameters_builds_the_bound_concrete(): void
    {
        $container = new Container();
        $container->bind(PersonInterface::class, Person::class);

        $person = $container->make(PersonInterface::class, ['name' => 'Frodo']);

        self::assertInstanceOf(Person::class, $person);
        self::assertSame('Frodo', $person->name);
    }

    public function test_make_with_parameters_follows_an_alias(): void
    {
        $container = new Container();
        $container->bind(PersonInterface::class, Person::class);
        $container->alias('hobbit', PersonInterface::class);

        /** @phpstan-ignore argument.type, argument.templateType */
        $person = $container->make('hobbit', ['name' => 'Sam']);

        self::assertInstanceOf(Person::class, $person);
        self::assertSame('Sam', $person->name);
    }

    public function test_make_with_parameters_in_a_scope_follows_the_parent_binding(): void
    {
        $container = new Container();
        $container->bind(PersonInterface::class, Person::class);

        $person = $container->createScope()->make(PersonInterface::class, ['name' => 'Pippin']);

        self::assertInstanceOf(Person::class, $person);
        self::assertSame('Pippin', $person->name);
    }

    public function test_make_rejects_a_parameter_the_constructor_does_not_have(): void
    {
        $container = new Container();

        $this->expectException(DependencyInvalidArgumentException::class);
        $this->expectExceptionMessageMatches(
            "/'apiKy'.*ServiceWithScalarDependency.*\\\$person, \\\$apiKey.*Did you mean.*- apiKey/s",
        );

        $container->make(ServiceWithScalarDependency::class, ['apiKy' => 'xyz']);
    }

    public function test_make_names_every_unknown_parameter(): void
    {
        $container = new Container();

        $this->expectException(DependencyInvalidArgumentException::class);
        $this->expectExceptionMessage("'nope', 'other'");

        $container->make(Person::class, ['name' => 'Merry', 'nope' => 1, 'other' => 2]);
    }

    public function test_make_rejects_parameters_for_a_class_without_a_constructor(): void
    {
        $container = new Container();

        $this->expectException(DependencyInvalidArgumentException::class);
        $this->expectExceptionMessage('takes no constructor parameters');

        $container->make(ClassWithoutDependencies::class, ['name' => 'x']);
    }
}
