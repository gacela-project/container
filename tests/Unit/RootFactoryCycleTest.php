<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\ContainerInterface;
use Gacela\Container\Exception\CircularDependencyException;
use GacelaTest\Fake\ClassWithObjectDependencies;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\ForwardingContainer;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\ServiceWithMixedConstructor;
use GacelaTest\Fake\ServiceWithScalarDependency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RootFactoryCycleTest extends TestCase
{
    protected function setUp(): void
    {
        ini_set('memory_limit', '64M');
        set_time_limit(3);
        Container::resetStaticCaches();
    }

    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function cyclicFactories(): iterable
    {
        foreach (['bind', 'singleton', 'contextual'] as $registration) {
            foreach ([false, true] as $callableArray) {
                foreach ([false, true] as $scoped) {
                    yield $registration . ($callableArray ? ' array' : ' closure') . ($scoped ? ' scope' : ' root') => [
                        $registration,
                        $callableArray,
                        $scoped,
                    ];
                }
            }
        }
    }

    public static function createCyclicPerson(ContainerInterface $container): Person
    {
        $container->get(ClassWithObjectDependencies::class);

        return new Person();
    }

    #[DataProvider('cyclicFactories')]
    public function test_factory_cycles_throw_and_leave_the_container_reusable(string $registration, bool $callableArray, bool $scoped): void
    {
        $container = $scoped ? (new Container())->createScope() : new Container();
        $factory = $callableArray ? [self::class, 'createCyclicPerson'] : self::createCyclicPerson(...);
        self::register($container, $registration, $factory);

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $failure = null;

            try {
                $container->get(ClassWithObjectDependencies::class);
            } catch (CircularDependencyException $exception) {
                $failure = $exception;
            }

            self::assertInstanceOf(CircularDependencyException::class, $failure);
        }

        self::register($container, $registration, static fn (): Person => new Person('recovered'));

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            self::assertSame('recovered', $container->get(ClassWithObjectDependencies::class)->person->name);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function registrations(): iterable
    {
        foreach (['bind', 'singleton', 'contextual'] as $registration) {
            yield $registration => [$registration];
        }
    }

    #[DataProvider('registrations')]
    public function test_a_factory_can_finish_a_reentrant_make_with_constructor_overrides(string $registration): void
    {
        $container = new Container();
        $calls = 0;
        self::register($container, $registration, static function (ContainerInterface $current) use (&$calls): Person {
            ++$calls;

            return $current->make(ClassWithObjectDependencies::class, ['person' => new Person('supplied')])->person;
        });

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            self::assertSame('supplied', $container->get(ClassWithObjectDependencies::class)->person->name);
        }

        self::assertSame($registration === 'singleton' ? 1 : 3, $calls);
    }

    #[DataProvider('registrations')]
    public function test_a_factory_can_publish_a_ready_dependency_before_reentering_its_consumer(string $registration): void
    {
        $container = new Container();
        $ready = new Person('ready');
        $calls = 0;
        self::register($container, $registration, static function (ContainerInterface $current) use ($container, $registration, $ready, &$calls): Person {
            ++$calls;
            if ($registration === 'contextual') {
                $container->when(ClassWithObjectDependencies::class)->needs(Person::class)->give($ready);
            } else {
                $current->set(Person::class, $ready);
            }

            return $current->get(ClassWithObjectDependencies::class)->person;
        });

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            self::assertSame($ready, $container->get(ClassWithObjectDependencies::class)->person);
        }

        self::assertSame(1, $calls);
    }

    #[DataProvider('registrations')]
    public function test_a_replacement_factory_can_finish_reentrant_resolution(string $registration): void
    {
        $container = new Container();
        $ready = new Person('rebound');
        self::register($container, $registration, static function (ContainerInterface $current) use ($container, $registration, $ready): Person {
            self::register($container, $registration, static fn (): Person => $ready);

            return $current->get(ClassWithObjectDependencies::class)->person;
        });

        self::assertSame($ready, $container->get(ClassWithObjectDependencies::class)->person);
        self::assertSame($ready, $container->get(ClassWithObjectDependencies::class)->person);
    }

    public function test_the_same_contextual_factory_can_serve_a_different_consumer_during_reentry(): void
    {
        $container = new Container();
        $calls = 0;
        $factory = static function (ContainerInterface $current) use (&$calls): Person {
            if (++$calls === 1) {
                return $current->make(ServiceWithScalarDependency::class, ['apiKey' => 'configured'])->person;
            }

            return new Person('nested');
        };
        $container->when(ClassWithObjectDependencies::class)->needs(Person::class)->give($factory);
        $container->when(ServiceWithScalarDependency::class)->needs(Person::class)->give($factory);

        self::assertSame('nested', $container->get(ClassWithObjectDependencies::class)->person->name);
        self::assertSame(2, $calls);
    }

    public function test_the_same_named_factory_can_supply_another_parameter_during_reentry(): void
    {
        $container = new Container();
        $calls = 0;
        $factory = static function (ContainerInterface $current) use (&$calls): Person {
            if (++$calls === 1) {
                $person = $current->make(ServiceWithMixedConstructor::class, ['first' => new Person('supplied')])->second;
                self::assertInstanceOf(Person::class, $person);

                return $person;
            }

            return new Person('nested');
        };
        $container->when(ServiceWithMixedConstructor::class)->needs('$first')->give($factory);
        $container->when(ServiceWithMixedConstructor::class)->needs('$second')->give($factory);

        $service = $container->get(ServiceWithMixedConstructor::class);

        self::assertSame('nested', $service->first?->name);
        self::assertSame('nested', $service->second?->name);
        self::assertSame(3, $calls);
    }

    #[DataProvider('registrations')]
    public function test_acyclic_nested_factories_keep_the_current_scope_facade(string $registration): void
    {
        $parent = new Container();
        $parent->set(ClassWithoutDependencies::class, new ClassWithoutDependencies());
        $scope = $parent->createScope();
        $local = new ClassWithoutDependencies();
        $scope->set(ClassWithoutDependencies::class, $local);
        $facade = new ForwardingContainer($scope);
        $scope->withSelfReference($facade);
        $received = [];
        self::register($scope, $registration, static function (ContainerInterface $current) use (&$received, $facade, $local): Person {
            $received[] = $current;
            self::assertSame($facade, $current);
            self::assertSame($local, $current->get(ClassWithoutDependencies::class));

            return new Person('acyclic');
        });

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            self::assertSame('acyclic', $facade->get(ClassWithObjectDependencies::class)->person->name);
        }

        self::assertCount($registration === 'singleton' ? 1 : 3, $received);
    }

    public function test_an_unrelated_factory_exception_is_preserved_and_can_be_retried(): void
    {
        $container = new Container();
        $failure = new RuntimeException('factory failed');
        $attempts = 0;
        $container->bind(Person::class, static function () use (&$attempts, $failure): Person {
            if (++$attempts === 1) {
                throw $failure;
            }

            return new Person('retried');
        });

        try {
            $container->get(ClassWithObjectDependencies::class);
            self::fail('The factory should throw on its first attempt.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame('retried', $container->get(ClassWithObjectDependencies::class)->person->name);
    }

    public function test_changing_override_values_does_not_hide_an_unresolved_cycle(): void
    {
        $container = new Container();
        $container->bind(Person::class, static function (ContainerInterface $current): Person {
            return $current->make(ServiceWithScalarDependency::class, ['apiKey' => 'inner'])->person;
        });

        $this->expectException(CircularDependencyException::class);
        $container->make(ServiceWithScalarDependency::class, ['apiKey' => 'outer']);
    }

    public function test_reentrant_overrides_can_leave_other_parameters_to_the_resolver(): void
    {
        $container = new Container();
        $container->when(ServiceWithMixedConstructor::class)->needs('$first')->give(
            static function (ContainerInterface $current): Person {
                $inner = $current->make(ServiceWithMixedConstructor::class, ['first' => new Person('supplied')]);
                self::assertSame('x', $inner->label);
                self::assertNull($inner->second);
                self::assertNull($inner->last);
                self::assertInstanceOf(Person::class, $inner->first);

                return $inner->first;
            },
        );

        self::assertSame('supplied', $container->get(ServiceWithMixedConstructor::class)->first?->name);
    }

    private static function register(Container $container, string $registration, callable $factory): void
    {
        if ($registration === 'contextual') {
            $container->when(ClassWithObjectDependencies::class)->needs(Person::class)->give($factory);

            return;
        }

        if ($registration === 'singleton') {
            $container->singleton(Person::class, $factory);

            return;
        }

        $container->bind(Person::class, $factory);
    }
}
