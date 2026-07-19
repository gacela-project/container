<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use GacelaTest\Fake\ClassWithObjectDependencies;
use GacelaTest\Fake\Person;
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
}
