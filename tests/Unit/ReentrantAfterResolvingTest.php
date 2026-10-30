<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\ContainerInterface;
use Gacela\Container\Exception\CircularDependencyException;
use GacelaTest\Fake\AlternativeCompilableDependency;
use GacelaTest\Fake\ClassWithObjectDependencies;
use GacelaTest\Fake\ClassWithRelationship;
use GacelaTest\Fake\CompilableConsumer;
use GacelaTest\Fake\CompilableDependency;
use GacelaTest\Fake\ConsumerOfSingletonAttributeService;
use GacelaTest\Fake\ForwardingContainer;
use GacelaTest\Fake\OtherConsumerOfSingletonAttributeService;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\SelfReferential;
use GacelaTest\Fake\SingletonAttributeService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ReentrantAfterResolvingTest extends TestCase
{
    protected function setUp(): void
    {
        ini_set('memory_limit', '64M');
        set_time_limit(3);
        Container::resetStaticCaches();
    }

    public function test_an_attribute_singleton_hook_can_resolve_another_consumer(): void
    {
        $container = new Container();
        $calls = 0;
        $container->afterResolving(SingletonAttributeService::class, static function (SingletonAttributeService $service, ContainerInterface $current) use (&$calls): void {
            ++$calls;
            self::assertSame($service, $current->get(OtherConsumerOfSingletonAttributeService::class)->service);
        });

        $shared = $container->get(ConsumerOfSingletonAttributeService::class)->service;
        self::assertSame($shared, $container->get(SingletonAttributeService::class));
        self::assertSame($shared, $container->get(ConsumerOfSingletonAttributeService::class)->service);
        self::assertSame(1, $calls);
    }

    public function test_a_completed_dependency_hook_can_reenter_the_same_consumer_class(): void
    {
        $container = new Container();
        $fromHook = null;
        $calls = 0;
        $container->afterResolving(SingletonAttributeService::class, static function (SingletonAttributeService $service, ContainerInterface $current) use (&$fromHook, &$calls): void {
            ++$calls;
            $fromHook = $current->get(ConsumerOfSingletonAttributeService::class);
            self::assertSame($service, $fromHook->service);
        });

        $consumer = $container->get(ConsumerOfSingletonAttributeService::class);

        self::assertInstanceOf(ConsumerOfSingletonAttributeService::class, $fromHook);
        self::assertNotSame($consumer, $fromHook);
        self::assertSame($consumer->service, $fromHook->service);
        self::assertSame(1, $calls);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function sharedRegistrations(): iterable
    {
        foreach (['class', 'callable', 'stored definition', 'stored object', 'bound object', 'aliased class', 'aliased callable', 'aliased definition'] as $registration) {
            foreach ([false, true] as $scoped) {
                yield $registration . ($scoped ? ' scope' : ' root') => [$registration, $scoped];
            }
        }
    }

    #[DataProvider('sharedRegistrations')]
    public function test_shared_service_hooks_can_resolve_another_consumer(string $registration, bool $scoped): void
    {
        $container = $scoped ? (new Container())->createScope() : new Container();
        self::registerPerson($container, $registration);
        $facade = new ForwardingContainer($container);
        $container->withSelfReference($facade);
        $calls = 0;
        $container->afterResolving(Person::class, static function (Person $person, ContainerInterface $current) use (&$calls, $facade): void {
            ++$calls;
            self::assertSame($facade, $current);
            $other = $current->get(ClassWithRelationship::class);
            self::assertSame($person, $other->person1);
            self::assertSame($person, $other->person2);
        });

        $shared = $facade->get(ClassWithObjectDependencies::class)->person;
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            self::assertSame($shared, $facade->get(ClassWithObjectDependencies::class)->person);
        }
        self::assertSame(1, $calls);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rebuildableRegistrations(): iterable
    {
        foreach (['class', 'callable', 'stored definition', 'aliased class', 'aliased callable', 'aliased definition'] as $registration) {
            yield $registration => [$registration];
        }
    }

    #[DataProvider('rebuildableRegistrations')]
    public function test_a_failed_reentrant_hook_evicts_the_shared_object_and_can_retry(string $registration): void
    {
        $container = new Container();
        self::registerPerson($container, $registration);
        $failure = new RuntimeException('hook failed');
        $failed = null;
        $calls = 0;
        $container->afterResolving(Person::class, static function (Person $person, ContainerInterface $current) use (&$failed, &$calls, $failure): void {
            ++$calls;
            self::assertSame($person, $current->get(ClassWithRelationship::class)->person1);
            if ($failed === null) {
                $failed = $person;
                $person->name = 'half configured';
                throw $failure;
            }
        });

        try {
            $container->get(ClassWithObjectDependencies::class);
            self::fail('The hook should fail on the first object.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        $rebuilt = $container->get(ClassWithObjectDependencies::class)->person;
        self::assertNotSame($failed, $rebuilt);
        self::assertSame('', $rebuilt->name);
        self::assertSame($rebuilt, $container->get(ClassWithObjectDependencies::class)->person);
        self::assertSame(2, $calls);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function guardedRegistrations(): iterable
    {
        foreach (['class', 'stored definition', 'aliased class', 'aliased callable', 'aliased definition'] as $registration) {
            yield $registration => [$registration];
        }
    }

    #[DataProvider('guardedRegistrations')]
    public function test_forgetting_in_a_hook_does_not_allow_reentry_into_a_new_construction(string $registration): void
    {
        $container = new Container();
        self::registerPerson($container, $registration);
        $container->afterResolving(Person::class, static function (Person $person, ContainerInterface $current): void {
            self::assertInstanceOf(Container::class, $current);
            $current->forget(Person::class);
            $current->get(ClassWithRelationship::class);
        });

        $this->expectException(CircularDependencyException::class);
        $container->get(ClassWithObjectDependencies::class);
    }

    public function test_an_unbuilt_singleton_registration_does_not_bypass_a_real_constructor_cycle(): void
    {
        $container = new Container();
        $container->singleton(SelfReferential::class);
        $container->afterResolving(SelfReferential::class, static function (): void {
            self::fail('A constructor cycle must fail before hooks run.');
        });

        $this->expectException(CircularDependencyException::class);
        $container->get(SelfReferential::class);
    }

    public function test_a_factory_keeps_fresh_dependencies_and_hooks_per_object(): void
    {
        $container = new Container();
        $container->set(Person::class, $container->factory(static fn (): Person => new Person()));
        $calls = 0;
        $container->afterResolving(Person::class, static function () use (&$calls): void {
            ++$calls;
        });
        $first = $container->get(ClassWithObjectDependencies::class)->person;
        $second = $container->get(ClassWithObjectDependencies::class)->person;

        self::assertNotSame($first, $second);
        self::assertSame(2, $calls);
    }

    public function test_an_unshared_factory_cannot_bypass_the_construction_guard_from_a_hook(): void
    {
        $container = new Container();
        $container->set(Person::class, $container->factory(static fn (): Person => new Person()));
        $container->afterResolving(Person::class, static function (Person $person, ContainerInterface $current): void {
            $current->get(ClassWithRelationship::class);
        });

        $this->expectException(CircularDependencyException::class);
        $container->get(ClassWithObjectDependencies::class);
    }

    public function test_parent_and_scope_hooks_can_resolve_consumers_of_the_same_parent_owned_service(): void
    {
        $parent = new Container();
        self::registerPerson($parent, 'aliased callable');
        $scope = $parent->createScope();
        $calls = [];
        foreach (['parent' => $parent, 'scope' => $scope] as $name => $container) {
            $container->afterResolving(Person::class, static function (Person $person, ContainerInterface $current) use (&$calls, $name, $container): void {
                $calls[] = $name;
                self::assertSame($container, $current);
                self::assertSame($person, $current->get(ClassWithRelationship::class)->person1);
            });
        }

        $shared = $scope->get(ClassWithObjectDependencies::class)->person;
        self::assertSame($shared, $parent->get(Person::class));
        self::assertSame($shared, $scope->get(ClassWithObjectDependencies::class)->person);
        self::assertSame(['parent', 'scope'], $calls);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function scopeDepths(): iterable
    {
        yield 'root' => [0];
        yield 'scope' => [1];
        yield 'grandchild scope' => [2];
    }

    #[DataProvider('scopeDepths')]
    public function test_a_contextual_singleton_hook_can_resolve_another_contextual_consumer(int $depth): void
    {
        $parent = new Container();
        $parent->singleton(CompilableDependency::class);
        $parent->bind(CompilableDependency::class, AlternativeCompilableDependency::class);
        $parent->when(CompilableConsumer::class)->needs(CompilableDependency::class)->give(CompilableDependency::class);
        $container = $parent;
        for ($level = 0; $level < $depth; ++$level) {
            $container = $container->createScope();
        }

        $calls = 0;
        $container->afterResolving(CompilableDependency::class, static function (CompilableDependency $dependency, ContainerInterface $current) use (&$calls): void {
            ++$calls;
            self::assertSame($dependency, $current->resolve(static fn (CompilableConsumer $consumer): CompilableDependency => $consumer->dependency));
        });

        $shared = $container->get(CompilableConsumer::class)->dependency;
        self::assertSame(CompilableDependency::class, $shared::class);
        self::assertSame($shared, $container->get(CompilableConsumer::class)->dependency);
        self::assertSame(1, $calls);
    }

    public function test_a_contextual_transient_shadow_cannot_reuse_a_parent_singleton(): void
    {
        $parent = new Container();
        $parent->singleton(CompilableDependency::class);
        $parent->get(CompilableDependency::class);
        $parent->when(CompilableConsumer::class)->needs(CompilableDependency::class)->give(CompilableDependency::class);
        $scope = $parent->createScope();
        $scope->bind(CompilableDependency::class, AlternativeCompilableDependency::class);
        $scope->afterResolving(CompilableDependency::class, static function (CompilableDependency $dependency, ContainerInterface $current): void {
            $current->resolve(static fn (CompilableConsumer $consumer): CompilableDependency => $consumer->dependency);
        });

        $this->expectException(CircularDependencyException::class);
        $scope->get(CompilableConsumer::class);
    }

    private static function registerPerson(Container $container, string $registration): void
    {
        $id = Person::class;
        if (str_starts_with($registration, 'aliased ')) {
            $id = 'person.shared';
            $container->alias(Person::class, $id);
            $registration = substr($registration, 8);
            $registration = $registration === 'definition' ? 'stored definition' : $registration;
        }

        if ($registration === 'class') {
            $container->singleton($id, Person::class);
        } elseif ($registration === 'callable') {
            $container->singleton($id, static fn (): Person => new Person());
        } elseif ($registration === 'stored definition') {
            $container->set($id, static fn (): Person => new Person());
        } elseif ($registration === 'stored object') {
            $container->set($id, new Person());
        } else {
            $container->bind($id, new Person());
        }
    }
}
