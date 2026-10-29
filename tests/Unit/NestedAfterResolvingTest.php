<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\ContainerInterface;
use GacelaTest\Fake\ClassWithDependencyWithoutDependencies;
use GacelaTest\Fake\ClassWithInterfaceDependencies;
use GacelaTest\Fake\ClassWithObjectDependencies;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\ForwardingContainer;
use GacelaTest\Fake\InMemoryRepository;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\PersonInterface;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\ServiceWithRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeakReference;

final class NestedAfterResolvingTest extends TestCase
{
    protected function setUp(): void
    {
        Container::resetStaticCaches();
    }

    protected function tearDown(): void
    {
        Container::resetStaticCaches();
    }

    public function test_a_type_hook_configures_nested_dependencies_on_every_build(): void
    {
        $container = new Container();
        $container->afterResolving(PersonInterface::class, static function (Person $person): void {
            $person->name = 'configured';
        });

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame('configured', $container->get(ClassWithObjectDependencies::class)->person->name);
        }
    }

    public function test_hooks_reach_nested_instances_in_a_composable_graph(): void
    {
        $container = new Container();
        $seen = [];
        $container->afterResolving(ClassWithoutDependencies::class, static function (object $instance) use (&$seen): void {
            $seen[] = $instance;
        });

        for ($i = 0; $i < 3; ++$i) {
            $service = $container->get(ClassWithDependencyWithoutDependencies::class);

            self::assertSame($service->classWithoutDependencies, $seen[$i] ?? null);
        }

        self::assertCount(3, $seen);
    }

    public function test_registering_hooks_discards_existing_composed_builders(): void
    {
        $container = new Container();
        for ($i = 0; $i < 3; ++$i) {
            $container->get(ClassWithDependencyWithoutDependencies::class);
        }

        $seen = [];
        $container->afterResolving(ClassWithoutDependencies::class, static function (object $instance) use (&$seen): void {
            $seen[] = $instance;
        });

        for ($i = 0; $i < 3; ++$i) {
            $service = $container->get(ClassWithDependencyWithoutDependencies::class);

            self::assertSame($service->classWithoutDependencies, $seen[$i] ?? null);
        }
    }

    #[DataProvider('registrationOrderProvider')]
    public function test_generated_factories_preserve_nested_hooks(bool $hooksFirst): void
    {
        $file = tempnam(sys_get_temp_dir(), 'compiled') . '.php';
        (new Container())->writeCompiledFactories([
            ClassWithDependencyWithoutDependencies::class,
            ClassWithoutDependencies::class,
        ], $file);
        $factories = Container::loadCompiledFactories($file);
        @unlink($file);

        $container = new Container();
        $seen = [];
        $callback = static function (object $instance) use (&$seen): void {
            $seen[] = $instance;
        };

        if ($hooksFirst) {
            $container->afterResolving(ClassWithoutDependencies::class, $callback);
        }

        $container->useCompiledFactories($factories);

        if (!$hooksFirst) {
            $container->get(ClassWithDependencyWithoutDependencies::class);
            $container->afterResolving(ClassWithoutDependencies::class, $callback);
        }

        for ($i = 0; $i < 3; ++$i) {
            $service = $container->get(ClassWithDependencyWithoutDependencies::class);

            self::assertSame($service->classWithoutDependencies, $seen[$i] ?? null);
        }
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function registrationOrderProvider(): iterable
    {
        yield 'hooks before factories' => [true];
        yield 'hooks after factories' => [false];
    }

    /**
     * @param class-string|object $binding
     */
    #[DataProvider('bindingProvider')]
    public function test_hooks_configure_nested_global_binding_results(string|object $binding): void
    {
        $container = new Container([PersonInterface::class => $binding]);
        $container->afterResolving(PersonInterface::class, static function (Person $person): void {
            $person->name = 'configured';
        });

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame('configured', $container->get(ClassWithInterfaceDependencies::class)->person->name);
        }
    }

    /**
     * @param class-string|object $binding
     */
    #[DataProvider('bindingProvider')]
    public function test_hooks_configure_nested_contextual_binding_results(string|object $binding): void
    {
        $container = new Container();
        $container->when(ClassWithInterfaceDependencies::class)->needs(PersonInterface::class)->give($binding);
        $container->afterResolving(PersonInterface::class, static function (Person $person): void {
            $person->name = 'configured';
        });

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame('configured', $container->get(ClassWithInterfaceDependencies::class)->person->name);
        }
    }

    public function test_a_global_binding_factory_delegating_to_get_delivers_hooks_once(): void
    {
        $container = new Container();
        $container->bind(PersonInterface::class, static fn (ContainerInterface $current): mixed => $current->get(Person::class));
        $seen = [];
        $container->afterResolving(PersonInterface::class, static function (Person $person) use (&$seen): void {
            $seen[] = $person;
        });

        for ($i = 0; $i < 3; ++$i) {
            $seen = [];
            $service = $container->get(ClassWithInterfaceDependencies::class);

            self::assertSame([$service->person], $seen);
        }
    }

    public function test_a_contextual_binding_factory_delegating_to_get_delivers_hooks_once(): void
    {
        $container = new Container();
        $container->when(ClassWithInterfaceDependencies::class)
            ->needs(PersonInterface::class)
            ->give(static fn (ContainerInterface $current): mixed => $current->get(Person::class));
        $seen = [];
        $container->afterResolving(PersonInterface::class, static function (Person $person) use (&$seen): void {
            $seen[] = $person;
        });

        for ($i = 0; $i < 3; ++$i) {
            $seen = [];
            $service = $container->get(ClassWithInterfaceDependencies::class);

            self::assertSame([$service->person], $seen);
        }
    }

    /**
     * @return iterable<string, array{class-string|object}>
     */
    public static function bindingProvider(): iterable
    {
        yield 'class' => [Person::class];
        yield 'object' => [new Person()];
        yield 'closure' => [static fn (): Person => new Person()];
    }

    public function test_scope_hooks_run_after_parent_hooks_for_a_parent_binding_result(): void
    {
        $container = new Container([RepositoryInterface::class => DatabaseRepository::class]);
        $order = [];
        $container->afterResolving(RepositoryInterface::class, static function () use (&$order): void {
            $order[] = 'parent';
        });
        $scope = $container->createScope();
        $scope->afterResolving(RepositoryInterface::class, static function (DatabaseRepository $repository) use (&$order): void {
            $repository->person->name = 'scope';
            $order[] = 'scope';
        });

        for ($i = 0; $i < 3; ++$i) {
            $order = [];
            $service = $scope->get(ServiceWithRepository::class);

            self::assertSame('scope', $service->repository->person->name);
            self::assertSame(['parent', 'scope'], $order);
        }
    }

    public function test_scope_hooks_configure_local_binding_overrides(): void
    {
        $container = new Container([RepositoryInterface::class => InMemoryRepository::class]);
        $scope = $container->createScope();
        $scope->bind(RepositoryInterface::class, DatabaseRepository::class);
        $scope->afterResolving(RepositoryInterface::class, static function (DatabaseRepository $repository): void {
            $repository->person->name = 'scope';
        });

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame('scope', $scope->get(ServiceWithRepository::class)->repository->person->name);
        }

        self::assertInstanceOf(InMemoryRepository::class, $container->get(RepositoryInterface::class));
    }

    public function test_parent_hooks_do_not_configure_dependencies_autowired_by_a_scope(): void
    {
        $container = new Container();
        $container->afterResolving(Person::class, static function (Person $person): void {
            $person->name = 'parent';
        });
        $scope = $container->createScope();

        self::assertSame('', $scope->get(ClassWithObjectDependencies::class)->person->name);
        self::assertSame('parent', $container->get(ClassWithObjectDependencies::class)->person->name);
    }

    public function test_get_delegation_delivers_nested_hooks_once(): void
    {
        $container = new Container();
        $container->singleton(ClassWithoutDependencies::class);
        $seen = [];
        $container->afterResolving(ClassWithoutDependencies::class, static function (object $instance) use (&$seen): void {
            $seen[] = $instance;
        });
        $service = $container->get(ClassWithDependencyWithoutDependencies::class);

        self::assertSame([$service->classWithoutDependencies], $seen);
    }

    public function test_nested_hooks_receive_the_current_facade_and_fall_back_to_the_owner(): void
    {
        $container = new Container();
        $seen = null;
        $container->afterResolving(Person::class, static function (object $instance, ContainerInterface $current) use (&$seen): void {
            $seen = $current;
        });
        $facade = new ForwardingContainer($container);
        $container->withSelfReference($facade);
        $container->get(ClassWithObjectDependencies::class);

        self::assertSame($facade, $seen);

        $seen = null;
        unset($facade);
        $container->get(ClassWithObjectDependencies::class);

        self::assertSame($container, $seen);
    }

    public function test_nested_hook_delivery_does_not_keep_the_container_alive(): void
    {
        $container = new Container();
        $container->afterResolving(Person::class, static function (): void {
        });
        $container->get(ClassWithObjectDependencies::class);
        $reference = WeakReference::create($container);
        unset($container);

        self::assertNull($reference->get());
    }
}
