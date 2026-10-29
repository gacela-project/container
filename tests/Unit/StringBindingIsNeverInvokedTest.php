<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\NameSharedWithAFunction;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\ServiceWithRepository;
use PHPUnit\Framework\TestCase;

use function class_exists;
use function function_exists;
use function is_callable;

final class StringBindingIsNeverInvokedTest extends TestCase
{
    public function test_the_fixture_really_does_collide(): void
    {
        // Guards the guard: without a function of that name, every assertion
        // below would pass on a container that still tested is_callable() first.
        self::assertTrue(class_exists(NameSharedWithAFunction::class));
        self::assertTrue(function_exists(NameSharedWithAFunction::class));
        self::assertIsCallable(NameSharedWithAFunction::class);
    }

    public function test_a_bound_class_string_is_instantiated_not_invoked(): void
    {
        $container = new Container([RepositoryInterface::class => NameSharedWithAFunction::class]);

        self::assertInstanceOf(
            NameSharedWithAFunction::class,
            $container->get(RepositoryInterface::class),
        );
    }

    public function test_the_same_holds_for_a_dependency_resolved_into_a_constructor(): void
    {
        $container = new Container([RepositoryInterface::class => NameSharedWithAFunction::class]);

        self::assertInstanceOf(
            NameSharedWithAFunction::class,
            $container->get(ServiceWithRepository::class)->repository,
        );
    }

    public function test_the_same_holds_for_a_contextual_binding(): void
    {
        $container = new Container();
        $container->when(ServiceWithRepository::class)
            ->needs(RepositoryInterface::class)
            ->give(NameSharedWithAFunction::class);

        // The contextual path in DependencyResolver::resolveClass() had the
        // same ordering and the same hazard.
        self::assertInstanceOf(
            NameSharedWithAFunction::class,
            $container->get(ServiceWithRepository::class)->repository,
        );
    }

    public function test_a_closure_binding_is_still_invoked(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, static fn (): NameSharedWithAFunction => new NameSharedWithAFunction());

        self::assertInstanceOf(NameSharedWithAFunction::class, $container->get(RepositoryInterface::class));
    }

    public function test_an_invokable_object_binding_is_returned_as_an_instance(): void
    {
        $container = new Container();
        $instance = new class() {
            public function __invoke(): NameSharedWithAFunction
            {
                return new NameSharedWithAFunction();
            }
        };
        $container->bind(RepositoryInterface::class, $instance);

        self::assertSame($instance, $container->get(RepositoryInterface::class));
    }

    public function test_a_singleton_class_string_is_not_invoked_when_it_names_a_function(): void
    {
        self::assertTrue(class_exists(NameSharedWithAFunction::class));
        self::assertIsCallable(NameSharedWithAFunction::class);
        $container = new Container();
        $container->singleton(NameSharedWithAFunction::class);
        $first = $container->get(NameSharedWithAFunction::class);

        self::assertInstanceOf(NameSharedWithAFunction::class, $first);
        self::assertSame($first, $container->get(NameSharedWithAFunction::class));
    }

    public function test_an_instance_binding_is_still_returned_as_is(): void
    {
        $instance = new ClassWithoutDependencies();
        $container = new Container();
        $container->bind(RepositoryInterface::class, $instance);

        self::assertSame($instance, $container->get(RepositoryInterface::class));
    }
}
