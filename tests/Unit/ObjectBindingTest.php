<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\ContainerInterface;
use GacelaTest\Fake\InMemoryRepository;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\ServiceWithRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvokableBoundRepository implements RepositoryInterface
{
    public int $calls = 0;

    public function __invoke(): string
    {
        ++$this->calls;

        return 'invoked';
    }
}

final class ObjectBindingTest extends TestCase
{
    #[DataProvider('registrationProvider')]
    public function test_an_invokable_object_is_returned_unchanged_on_repeated_gets(string $registration): void
    {
        $instance = new InvokableBoundRepository();
        $container = self::containerWithInstance($registration, $instance);

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame($instance, $container->get(RepositoryInterface::class));
        }

        self::assertSame(0, $instance->calls);
    }

    #[DataProvider('registrationProvider')]
    public function test_an_invokable_object_is_injected_unchanged_into_nested_consumers(string $registration): void
    {
        $instance = new InvokableBoundRepository();
        $container = self::containerWithInstance($registration, $instance);

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame($instance, $container->get(ServiceWithRepository::class)->repository);
        }

        self::assertSame(0, $instance->calls);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function registrationProvider(): iterable
    {
        yield 'constructor bindings' => ['constructor'];
        yield 'bind' => ['bind'];
        yield 'singleton' => ['singleton'];
    }

    public function test_a_type_contextual_invokable_object_is_injected_unchanged(): void
    {
        $instance = new InvokableBoundRepository();
        $container = new Container();
        $container->when(ServiceWithRepository::class)->needs(RepositoryInterface::class)->give($instance);

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame($instance, $container->get(ServiceWithRepository::class)->repository);
        }

        self::assertSame(0, $instance->calls);
    }

    public function test_a_parent_invokable_binding_keeps_its_identity_in_scopes(): void
    {
        $instance = new InvokableBoundRepository();
        $container = new Container();
        $container->bind(RepositoryInterface::class, $instance);
        $scope = $container->createScope();

        self::assertSame($instance, $scope->get(RepositoryInterface::class));
        self::assertSame($instance, $scope->get(ServiceWithRepository::class)->repository);
        self::assertSame($instance, $container->get(RepositoryInterface::class));
        self::assertSame(0, $instance->calls);
    }

    public function test_set_still_treats_an_invokable_object_as_a_shared_definition(): void
    {
        $definition = new InvokableBoundRepository();
        $container = new Container();
        $container->set('service', $definition);

        self::assertSame('invoked', $container->get('service'));
        self::assertSame('invoked', $container->get('service'));
        self::assertSame(1, $definition->calls);
    }

    public function test_callable_array_bindings_still_build_top_level_and_nested_instances(): void
    {
        $factory = self::repositoryFactory();
        $container = new Container();
        $container->bind(RepositoryInterface::class, [$factory, 'build']);
        $first = $container->get(RepositoryInterface::class);
        $second = $container->get(RepositoryInterface::class);
        $nested = $container->get(ServiceWithRepository::class)->repository;

        self::assertInstanceOf(InMemoryRepository::class, $first);
        self::assertInstanceOf(InMemoryRepository::class, $nested);
        self::assertNotSame($first, $second);
        self::assertSame(3, $factory->calls);
        self::assertSame($container, $factory->received);
    }

    public function test_callable_array_singletons_still_build_one_shared_instance(): void
    {
        $factory = self::repositoryFactory();
        $container = new Container();
        $container->singleton(RepositoryInterface::class, [$factory, 'build']);
        $first = $container->get(RepositoryInterface::class);
        $second = $container->get(RepositoryInterface::class);
        $nested = $container->get(ServiceWithRepository::class)->repository;

        self::assertInstanceOf(InMemoryRepository::class, $first);
        self::assertSame($first, $second);
        self::assertSame($first, $nested);
        self::assertSame(1, $factory->calls);
        self::assertSame($container, $factory->received);
    }

    public function test_callable_array_type_bindings_still_build_contextual_instances(): void
    {
        $factory = self::repositoryFactory();
        $container = new Container();
        $container->when(ServiceWithRepository::class)->needs(RepositoryInterface::class)->give([$factory, 'build']);
        $first = $container->get(ServiceWithRepository::class)->repository;
        $second = $container->get(ServiceWithRepository::class)->repository;

        self::assertInstanceOf(InMemoryRepository::class, $first);
        self::assertNotSame($first, $second);
        self::assertSame(2, $factory->calls);
        self::assertSame($container, $factory->received);
    }

    private static function containerWithInstance(string $registration, InvokableBoundRepository $instance): Container
    {
        if ($registration === 'constructor') {
            return new Container([RepositoryInterface::class => $instance]);
        }

        $container = new Container();
        $container->{$registration}(RepositoryInterface::class, $instance);

        return $container;
    }

    private static function repositoryFactory(): object
    {
        return new class() {
            public int $calls = 0;

            public ?ContainerInterface $received = null;

            public function build(ContainerInterface $container): RepositoryInterface
            {
                ++$this->calls;
                $this->received = $container;

                return new InMemoryRepository();
            }
        };
    }
}
