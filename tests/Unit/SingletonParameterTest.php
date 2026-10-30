<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use GacelaTest\Fake\AlternativeCompilableDependency;
use GacelaTest\Fake\ClassWithDependencyWithoutDependencies;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\CompilableConsumer;
use GacelaTest\Fake\CompilableDependency;
use GacelaTest\Fake\ConsumerOfSingletonAttributeService;
use GacelaTest\Fake\InMemoryRepository;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\ServiceWithRepository;
use GacelaTest\Fake\SingletonAttributeService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * A shared service is the same instance whether get() is asked for it or a
 * constructor parameter is. A scope always delegated the parameter to its
 * parent and got that; a root container built a fresh one.
 */
final class SingletonParameterTest extends TestCase
{
    protected function setUp(): void
    {
        Container::resetStaticCaches();
    }

    protected function tearDown(): void
    {
        Container::resetStaticCaches();
    }

    public function test_a_singleton_attribute_class_is_shared_as_a_parameter(): void
    {
        $container = new Container();

        $first = $container->get(ConsumerOfSingletonAttributeService::class)->service;

        self::assertSame($first, $container->get(SingletonAttributeService::class));
        self::assertSame($first, $container->get(ConsumerOfSingletonAttributeService::class)->service);
    }

    public function test_a_class_registered_with_singleton_is_shared_as_a_parameter(): void
    {
        $container = new Container();
        $container->singleton(ClassWithoutDependencies::class);

        $direct = $container->get(ClassWithoutDependencies::class);

        self::assertSame($direct, $container->get(ClassWithDependencyWithoutDependencies::class)->classWithoutDependencies);
        self::assertSame($direct, $container->make(ClassWithDependencyWithoutDependencies::class)->classWithoutDependencies);
    }

    public function test_an_abstract_registered_with_singleton_is_shared_as_a_parameter(): void
    {
        $container = new Container();
        $container->singleton(RepositoryInterface::class, InMemoryRepository::class);

        $first = $container->get(ServiceWithRepository::class)->repository;

        self::assertSame($first, $container->get(RepositoryInterface::class));
        self::assertSame($first, $container->get(ServiceWithRepository::class)->repository);
    }

    public function test_the_concrete_behind_a_singleton_abstract_is_shared_as_a_parameter(): void
    {
        $container = new Container();
        $container->singleton(RepositoryInterface::class, InMemoryRepository::class);

        self::assertSame(
            $container->get(InMemoryRepository::class),
            $container->resolve(static fn (InMemoryRepository $repository): InMemoryRepository => $repository),
        );
    }

    /**
     * Repeated resolutions of a consumer go through a composed builder after
     * the first, which must not flatten a shared parameter into a `new`.
     */
    public function test_a_singleton_parameter_stays_shared_across_repeated_resolutions(): void
    {
        $container = new Container();
        $shared = $container->get(SingletonAttributeService::class);

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame($shared, $container->get(ConsumerOfSingletonAttributeService::class)->service);
        }
    }

    #[DataProvider('contextualSingletonScopes')]
    public function test_a_contextual_target_keeps_its_registered_singleton_lifetime(int $depth): void
    {
        $parent = new Container();
        $parent->singleton(CompilableDependency::class);
        $parent->bind(CompilableDependency::class, AlternativeCompilableDependency::class);
        $parent->when(CompilableConsumer::class)
            ->needs(CompilableDependency::class)->give(CompilableDependency::class);
        $calls = 0;
        $parent->afterResolving(CompilableDependency::class, static function () use (&$calls): void {
            ++$calls;
        });
        $container = $parent;
        for ($i = 0; $i < $depth; ++$i) {
            $container = $container->createScope();
        }

        $first = $container->get(CompilableConsumer::class)->dependency;
        self::assertSame(CompilableDependency::class, $first::class);
        self::assertSame($first, $container->get(CompilableConsumer::class)->dependency);
        self::assertSame($first, $parent->get(CompilableConsumer::class)->dependency);
        self::assertSame($first, $parent->createScope()->get(CompilableConsumer::class)->dependency);
        self::assertSame(1, $calls);

        $parent->forget(CompilableDependency::class);
        $rebuilt = $container->get(CompilableConsumer::class)->dependency;
        self::assertNotSame($first, $rebuilt);
        self::assertSame($rebuilt, $parent->get(CompilableConsumer::class)->dependency);
        self::assertSame($rebuilt, $container->get(CompilableConsumer::class)->dependency);
        self::assertSame(2, $calls);
        if ($depth > 0) {
            $container->forget(CompilableDependency::class);
            self::assertSame($rebuilt, $container->get(CompilableConsumer::class)->dependency);
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function contextualSingletonScopes(): iterable
    {
        yield 'root' => [0];
        yield 'child scope' => [1];
        yield 'grandchild scope' => [2];
    }

    public function test_existing_scopes_see_a_late_contextual_singleton_registration(): void
    {
        $parent = new Container();
        $scope = $parent->createScope();
        $sibling = $parent->createScope();
        $scope->get(CompilableConsumer::class);
        $parent->singleton(CompilableDependency::class);
        $parent->bind(CompilableDependency::class, AlternativeCompilableDependency::class);
        $parent->when(CompilableConsumer::class)
            ->needs(CompilableDependency::class)->give(CompilableDependency::class);

        $first = $scope->get(CompilableConsumer::class)->dependency;
        self::assertSame(CompilableDependency::class, $first::class);
        self::assertSame($first, $parent->get(CompilableConsumer::class)->dependency);
        self::assertSame($first, $sibling->get(CompilableConsumer::class)->dependency);
    }

    public function test_a_scope_keeps_its_contextual_target_when_shadowing_an_inherited_singleton(): void
    {
        $parent = new Container();
        $parent->singleton(CompilableDependency::class);
        $parent->bind(CompilableDependency::class, AlternativeCompilableDependency::class);
        $parent->when(CompilableConsumer::class)
            ->needs(CompilableDependency::class)->give(CompilableDependency::class);
        $shared = $parent->get(CompilableConsumer::class)->dependency;
        $scope = $parent->createScope();
        $scope->singleton(CompilableDependency::class);
        $scope->bind(CompilableDependency::class, AlternativeCompilableDependency::class);
        $local = $scope->get(CompilableConsumer::class)->dependency;
        self::assertSame(CompilableDependency::class, $local::class);
        self::assertNotSame($shared, $local);
        self::assertSame($local, $scope->createScope()->get(CompilableConsumer::class)->dependency);

        $scope->forget(CompilableDependency::class);
        self::assertNotSame($local, $scope->get(CompilableConsumer::class)->dependency);
        self::assertSame($shared, $parent->get(CompilableConsumer::class)->dependency);

        $transientScope = $parent->createScope();
        $transientScope->bind(CompilableDependency::class, AlternativeCompilableDependency::class);
        $first = $transientScope->get(CompilableConsumer::class)->dependency;
        self::assertSame(CompilableDependency::class, $first::class);
        self::assertNotSame($shared, $first);
        self::assertNotSame($first, $transientScope->get(CompilableConsumer::class)->dependency);
    }

    #[DataProvider('storedContextualOverrides')]
    public function test_a_stored_scope_registration_outranks_the_contextual_singleton_cache(string $kind, bool $nested): void
    {
        $parent = new Container();
        $parent->singleton(CompilableDependency::class);
        $parent->bind(CompilableDependency::class, AlternativeCompilableDependency::class);
        $parent->when(CompilableConsumer::class)
            ->needs(CompilableDependency::class)->give(CompilableDependency::class);
        $shared = $parent->get(CompilableConsumer::class)->dependency;
        $scope = $parent->createScope();
        $local = new CompilableDependency();
        $calls = 0;
        $definition = static function () use ($local, &$calls, $kind): CompilableDependency {
            ++$calls;
            return $kind === 'factory' ? new CompilableDependency() : $local;
        };
        if ($kind === 'factory') {
            $scope->factory($definition);
        }
        $scope->set(CompilableDependency::class, $kind === 'object' ? $local : $definition);
        $consumerScope = $nested ? $scope->createScope() : $scope;

        $first = $consumerScope->get(CompilableConsumer::class)->dependency;
        $second = $consumerScope->get(CompilableConsumer::class)->dependency;
        self::assertNotSame($shared, $first);
        if ($kind === 'factory') {
            self::assertNotSame($first, $second);
            self::assertSame(2, $calls);
        } else {
            self::assertSame($local, $first);
            self::assertSame($first, $second);
            self::assertSame($kind === 'object' ? 0 : 1, $calls);
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function storedContextualOverrides(): iterable
    {
        foreach (['object', 'shared closure', 'factory'] as $kind) {
            yield $kind . ' on child' => [$kind, false];
            yield $kind . ' on middle scope' => [$kind, true];
        }
    }

    public function test_a_scope_alias_keeps_its_stored_contextual_override(): void
    {
        $parent = new Container();
        $parent->singleton(CompilableDependency::class);
        $parent->bind(CompilableDependency::class, AlternativeCompilableDependency::class);
        $parent->when(CompilableConsumer::class)
            ->needs(CompilableDependency::class)->give(CompilableDependency::class);
        $scope = $parent->createScope();
        $local = new CompilableDependency();
        $scope->set('local dependency', $local);
        $scope->alias(CompilableDependency::class, 'local dependency');

        self::assertSame($local, $scope->get(CompilableConsumer::class)->dependency);
        self::assertSame($local, $scope->createScope()->get(CompilableConsumer::class)->dependency);
    }

    public function test_a_contextual_singleton_notifies_every_scope_from_parent_to_child(): void
    {
        $parent = new Container();
        $parent->singleton(CompilableDependency::class);
        $parent->bind(CompilableDependency::class, AlternativeCompilableDependency::class);
        $parent->when(CompilableConsumer::class)
            ->needs(CompilableDependency::class)->give(CompilableDependency::class);
        $middle = $parent->createScope();
        $leaf = $middle->createScope();
        $order = [];
        foreach (['parent' => $parent, 'middle' => $middle, 'leaf' => $leaf] as $name => $container) {
            $container->afterResolving(CompilableDependency::class, static function () use ($name, &$order): void {
                $order[] = $name;
            });
        }

        $first = $leaf->get(CompilableConsumer::class)->dependency;
        self::assertSame(['parent', 'middle', 'leaf'], $order);
        self::assertSame($first, $leaf->get(CompilableConsumer::class)->dependency);
        self::assertSame(['parent', 'middle', 'leaf'], $order);

        $parent->forget(CompilableDependency::class);
        self::assertNotSame($first, $leaf->get(CompilableConsumer::class)->dependency);
        self::assertSame(['parent', 'middle', 'leaf', 'parent', 'middle', 'leaf'], $order);
    }

    public function test_a_contextual_interface_target_still_uses_its_concrete_singleton_binding(): void
    {
        $container = new Container();
        $container->singleton(RepositoryInterface::class, InMemoryRepository::class);
        $container->when(ServiceWithRepository::class)
            ->needs(RepositoryInterface::class)->give(RepositoryInterface::class);

        self::assertSame(
            $container->get(RepositoryInterface::class),
            $container->createScope()->get(ServiceWithRepository::class)->repository,
        );
    }

    public function test_a_scope_shares_a_singleton_parameter_it_resolved_itself(): void
    {
        $scope = (new Container())->createScope();

        self::assertSame(
            $scope->get(SingletonAttributeService::class),
            $scope->get(ConsumerOfSingletonAttributeService::class)->service,
        );
    }

    public function test_a_plan_cached_without_lifetime_flags_still_shares_a_singleton_parameter(): void
    {
        $container = new Container(compiledPlans: [
            SingletonAttributeService::class => [
                'instantiable' => true,
                'params' => (new Container())->compile([SingletonAttributeService::class])[SingletonAttributeService::class]['params'],
            ],
        ]);

        self::assertSame(
            $container->get(SingletonAttributeService::class),
            $container->get(ConsumerOfSingletonAttributeService::class)->service,
        );
    }

    public function test_a_singleton_parameter_is_not_compiled_into_its_consumer(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'gacela-singleton-param');
        self::assertIsString($file);

        $writer = new Container();
        $writer->singleton(ClassWithoutDependencies::class);
        $compiled = $writer->writeCompiledFactories([
            ClassWithDependencyWithoutDependencies::class,
            ConsumerOfSingletonAttributeService::class,
        ], $file);

        $container = new Container();
        $container->singleton(ClassWithoutDependencies::class);
        $container->useCompiledFactories(Container::loadCompiledFactories($file));
        unlink($file);

        self::assertSame([], $compiled);
        self::assertSame(
            $container->get(ClassWithoutDependencies::class),
            $container->get(ClassWithDependencyWithoutDependencies::class)->classWithoutDependencies,
        );
        self::assertSame(
            $container->get(SingletonAttributeService::class),
            $container->get(ConsumerOfSingletonAttributeService::class)->service,
        );
    }
}
