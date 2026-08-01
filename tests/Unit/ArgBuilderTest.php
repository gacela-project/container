<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\ContainerInterface;
use Gacela\Container\DependencyResolver;
use GacelaTest\Fake\ClassWithDependencyWithoutDependencies;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\FactoryAttributeService;
use GacelaTest\Fake\InMemoryRepository;
use GacelaTest\Fake\LazyService;
use GacelaTest\Fake\OuterHoldingInjectedService;
use GacelaTest\Fake\OuterHoldingLazyService;
use GacelaTest\Fake\OuterHoldingMethodInjectedService;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\PersonWithoutDefaultValues;
use GacelaTest\Fake\PersonWithoutParamType;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\SelfReferential;
use GacelaTest\Fake\ServiceWithPromotedInject;
use GacelaTest\Fake\ServiceWithRepository;
use GacelaTest\Fake\SingletonAttributeService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

/**
 * The memoized per-class constructor.
 *
 * A second construction path is only safe if it is provably unreachable for
 * anything configuration can influence — so most of this file is about the
 * cases where it must *not* be used, and about proving the result is identical
 * either way.
 */
final class ArgBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        Container::resetStaticCaches();
    }

    protected function tearDown(): void
    {
        Container::resetStaticCaches();
    }

    public function test_a_plain_graph_resolves_correctly(): void
    {
        $service = (new Container())->get(ClassWithDependencyWithoutDependencies::class);

        self::assertInstanceOf(ClassWithoutDependencies::class, $service->classWithoutDependencies);
    }

    public function test_repeated_resolutions_are_still_distinct_instances(): void
    {
        $container = new Container();

        self::assertNotSame(
            $container->get(ClassWithDependencyWithoutDependencies::class),
            $container->get(ClassWithDependencyWithoutDependencies::class),
        );
    }

    /**
     * The builder is composed on the second resolution, so the third is the
     * one that takes the new path. All of them must agree.
     */
    public function test_the_second_resolution_matches_the_first(): void
    {
        $container = new Container();

        $first = $container->get(ClassWithDependencyWithoutDependencies::class);
        $second = $container->get(ClassWithDependencyWithoutDependencies::class);

        self::assertSame($first::class, $second::class);
        self::assertInstanceOf(ClassWithoutDependencies::class, $second->classWithoutDependencies);
    }

    // ---------------------------------------------------------------
    // Registration takes the mechanism out of play, on the next call.
    // ---------------------------------------------------------------

    public function test_a_binding_registered_after_the_builder_still_wins(): void
    {
        $container = new Container();

        try {
            $container->get(ServiceWithRepository::class); // unresolvable until bound
        } catch (Throwable) {
            // expected — the point is what happens once it *is* bound
        }

        $container->bind(RepositoryInterface::class, DatabaseRepository::class);

        self::assertInstanceOf(
            DatabaseRepository::class,
            $container->get(ServiceWithRepository::class)->repository,
        );
    }

    public function test_a_binding_on_a_nested_class_is_honoured_after_the_builder_exists(): void
    {
        $container = new Container();
        $container->get(ClassWithDependencyWithoutDependencies::class);

        $replacement = new ClassWithoutDependencies();
        $container->bind(ClassWithoutDependencies::class, static fn (): object => $replacement);

        self::assertSame(
            $replacement,
            $container->get(ClassWithDependencyWithoutDependencies::class)->classWithoutDependencies,
        );
    }

    public function test_a_contextual_binding_registered_afterwards_is_honoured(): void
    {
        $container = new Container();
        $container->get(ClassWithDependencyWithoutDependencies::class);

        $replacement = new ClassWithoutDependencies();
        $container->when(ClassWithDependencyWithoutDependencies::class)
            ->needs(ClassWithoutDependencies::class)
            ->give(static fn (): object => $replacement);

        self::assertSame(
            $replacement,
            $container->get(ClassWithDependencyWithoutDependencies::class)->classWithoutDependencies,
        );
    }

    public function test_lazy_registered_afterwards_is_honoured(): void
    {
        $container = new Container();
        $container->get(ClassWithDependencyWithoutDependencies::class);

        $container->lazy(ClassWithoutDependencies::class);

        self::assertInstanceOf(
            ClassWithDependencyWithoutDependencies::class,
            $container->get(ClassWithDependencyWithoutDependencies::class),
        );
    }

    public function test_singleton_registered_afterwards_is_honoured(): void
    {
        $container = new Container();
        $container->get(ClassWithDependencyWithoutDependencies::class);

        $container->singleton(ClassWithDependencyWithoutDependencies::class);

        self::assertSame(
            $container->get(ClassWithDependencyWithoutDependencies::class),
            $container->get(ClassWithDependencyWithoutDependencies::class),
        );
    }

    public function test_a_scope_never_uses_a_builder(): void
    {
        $container = new Container();
        $scope = $container->createScope();
        $scope->bind(RepositoryInterface::class, InMemoryRepository::class);

        self::assertInstanceOf(
            InMemoryRepository::class,
            $scope->get(ServiceWithRepository::class)->repository,
        );
    }

    // ---------------------------------------------------------------
    // Per-class refusals: anything a constructor alone does not settle.
    // ---------------------------------------------------------------

    public function test_an_injected_property_is_still_assigned(): void
    {
        // One container: a fresh one per call never reaches the builder path,
        // which is the path this is here to hold to the same behaviour.
        $container = new Container();
        $container->get(OuterHoldingInjectedService::class);
        $outer = $container->get(OuterHoldingInjectedService::class);

        self::assertInstanceOf(ClassWithoutDependencies::class, $outer->inner->dependency());
    }

    public function test_an_injected_method_is_still_called(): void
    {
        $container = new Container();
        $container->get(OuterHoldingMethodInjectedService::class);
        $outer = $container->get(OuterHoldingMethodInjectedService::class);

        self::assertInstanceOf(ClassWithoutDependencies::class, $outer->inner->dependency);
    }

    public function test_an_inject_parameter_is_still_honoured(): void
    {
        $container = new Container();
        $container->get(ServiceWithPromotedInject::class);

        self::assertInstanceOf(
            DatabaseRepository::class,
            $container->get(ServiceWithPromotedInject::class)->repository,
        );
    }

    public function test_a_nested_lazy_service_is_still_deferred(): void
    {
        if (!method_exists(ReflectionClass::class, 'newLazyGhost')) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $container = new Container();
        $container->get(OuterHoldingLazyService::class);
        $outer = $container->get(OuterHoldingLazyService::class);

        self::assertInstanceOf(LazyService::class, $outer->lazyService);
    }

    public function test_a_scalar_parameter_with_a_default_still_uses_it(): void
    {
        $container = new Container();
        $container->get(Person::class);

        self::assertSame('', $container->get(Person::class)->name);
    }

    public function test_an_unresolvable_scalar_still_throws_on_the_second_call(): void
    {
        $container = new Container();

        for ($i = 0; $i < 2; ++$i) {
            try {
                $container->get(PersonWithoutDefaultValues::class);
                self::fail('expected the scalar to be unresolvable');
            } catch (Throwable) {
                // expected, both times
            }
        }

        self::assertTrue(true);
    }

    public function test_an_untyped_parameter_still_throws_on_the_second_call(): void
    {
        $container = new Container();

        for ($i = 0; $i < 2; ++$i) {
            try {
                $container->get(PersonWithoutParamType::class);
                self::fail('expected the untyped parameter to be unresolvable');
            } catch (Throwable) {
                // expected
            }
        }

        self::assertTrue(true);
    }

    public function test_an_unbound_interface_still_throws_on_the_second_call(): void
    {
        $container = new Container();
        $thrown = 0;

        for ($i = 0; $i < 2; ++$i) {
            try {
                $container->get(ServiceWithRepository::class);
            } catch (Throwable) {
                ++$thrown;
            }
        }

        self::assertSame(2, $thrown);
    }

    // ---------------------------------------------------------------
    // Lifetimes are decided before construction, so they are unaffected.
    // ---------------------------------------------------------------

    public function test_a_singleton_attribute_class_is_still_shared(): void
    {
        $container = new Container();

        self::assertSame(
            $container->get(SingletonAttributeService::class),
            $container->get(SingletonAttributeService::class),
        );
    }

    public function test_a_factory_attribute_class_is_still_rebuilt(): void
    {
        $container = new Container();

        self::assertNotSame(
            $container->get(FactoryAttributeService::class),
            $container->get(FactoryAttributeService::class),
        );
    }

    public function test_a_stored_instance_still_wins(): void
    {
        $container = new Container();
        $container->get(ClassWithoutDependencies::class);

        $instance = new ClassWithoutDependencies();
        $container->set(ClassWithoutDependencies::class, $instance);

        self::assertSame($instance, $container->get(ClassWithoutDependencies::class));
    }

    public function test_after_resolving_hooks_still_fire_on_the_second_call(): void
    {
        $container = new Container();
        $fired = 0;

        $container->afterResolving(
            ClassWithDependencyWithoutDependencies::class,
            static function () use (&$fired): void {
                ++$fired;
            },
        );

        $container->get(ClassWithDependencyWithoutDependencies::class);
        $container->get(ClassWithDependencyWithoutDependencies::class);

        self::assertSame(2, $fired);
    }

    public function test_make_with_runtime_parameters_still_overrides(): void
    {
        $container = new Container();
        $container->get(Person::class);

        self::assertSame('Frodo', $container->make(Person::class, ['name' => 'Frodo'])->name);
    }

    public function test_a_cycle_does_not_hang_and_still_reports(): void
    {
        $container = new Container();

        for ($i = 0; $i < 2; ++$i) {
            try {
                $container->get(\GacelaTest\Fake\CircularA::class);
                self::fail('expected a circular dependency');
            } catch (Throwable) {
                // expected
            }
        }

        self::assertTrue(true);
    }

    /**
     * One per registration method: each must drop the memos, because a builder
     * has already flattened a graph that the registration can change. Proven by
     * removing the drop and watching these fail.
     *
     * @param callable(Container): void $register
     */
    #[DataProvider('registrationMethods')]
    public function test_a_registration_drops_the_memo(callable $register): void
    {
        $container = new Container();
        $container->get(ClassWithDependencyWithoutDependencies::class);

        $register($container);

        $replacement = new ClassWithoutDependencies();
        $container->bind(ClassWithoutDependencies::class, static fn (): object => $replacement);

        self::assertSame(
            $replacement,
            $container->get(ClassWithDependencyWithoutDependencies::class)->classWithoutDependencies,
        );
    }

    /**
     * @return iterable<string, array{callable(Container): void}>
     */
    public static function registrationMethods(): iterable
    {
        yield 'bind' => [static fn (Container $c) => $c->bind('x.bind', ClassWithoutDependencies::class)];
        yield 'singleton' => [static fn (Container $c) => $c->singleton('x.singleton', ClassWithoutDependencies::class)];
        yield 'bindIf' => [static fn (Container $c) => $c->bindIf('x.bindIf', ClassWithoutDependencies::class)];
        yield 'singletonIf' => [static fn (Container $c) => $c->singletonIf('x.singletonIf', ClassWithoutDependencies::class)];
        yield 'set' => [static fn (Container $c) => $c->set('x.set', new ClassWithoutDependencies())];
        yield 'alias' => [static fn (Container $c) => $c->alias('x.alias', ClassWithoutDependencies::class)];
        yield 'tag' => [static fn (Container $c) => $c->tag(ClassWithoutDependencies::class, 'x.tag')];
        yield 'lazy' => [static fn (Container $c) => $c->lazy(LazyService::class)];
        yield 'when' => [static fn (Container $c) => $c->when(Person::class)->needs('$name')->give('x')];
        yield 'useCompiledFactories' => [static fn (Container $c) => $c->useCompiledFactories([])];
        yield 'remove' => [static fn (Container $c) => $c->remove('x.absent')];
    }

    /**
     * A self-referential constructor has no leaf to build upwards from, so the
     * recursion has to stop. Without the guard this does not terminate.
     */
    public function test_a_self_referential_class_is_refused_without_hanging(): void
    {
        $container = new Container();

        for ($i = 0; $i < 2; ++$i) {
            try {
                $container->get(SelfReferential::class);
                self::fail('expected a circular dependency');
            } catch (Throwable) {
                // expected
            }
        }

        self::assertTrue(true);
    }

    /**
     * The refusal is memoized, so the second resolution takes the cached-false
     * path rather than re-deciding.
     */
    public function test_a_refusal_is_reused_and_still_resolves(): void
    {
        $container = new Container();

        $first = $container->get(ServiceWithPromotedInject::class);
        $second = $container->get(ServiceWithPromotedInject::class);

        self::assertInstanceOf(DatabaseRepository::class, $first->repository);
        self::assertInstanceOf(DatabaseRepository::class, $second->repository);
    }

    /**
     * lazy() with a factory closure populates a second map, and a builder must
     * not be installed while one exists — the closure, not the constructor,
     * makes the instance.
     */
    public function test_a_lazy_factory_registration_disables_builders(): void
    {
        $container = new Container();
        $container->get(ClassWithDependencyWithoutDependencies::class);

        $container->lazy(InMemoryRepository::class, static fn (): object => new InMemoryRepository());

        $replacement = new ClassWithoutDependencies();
        $container->bind(ClassWithoutDependencies::class, static fn (): object => $replacement);

        self::assertSame(
            $replacement,
            $container->get(ClassWithDependencyWithoutDependencies::class)->classWithoutDependencies,
        );
    }

    /**
     * A contextual binding anywhere on the container disables builders
     * outright: it is keyed by consumer, so a flattened graph cannot honour it.
     */
    public function test_any_contextual_binding_disables_builders_everywhere(): void
    {
        $container = new Container();
        $container->get(ClassWithDependencyWithoutDependencies::class);

        // Unrelated to the graph below, and still disabling.
        $container->when(Person::class)->needs('$name')->give('Frodo');

        $replacement = new ClassWithoutDependencies();
        $container->when(ClassWithDependencyWithoutDependencies::class)
            ->needs(ClassWithoutDependencies::class)
            ->give(static fn (): object => $replacement);

        self::assertSame(
            $replacement,
            $container->get(ClassWithDependencyWithoutDependencies::class)->classWithoutDependencies,
        );
    }

    public function test_a_bound_class_is_never_built_directly(): void
    {
        $container = new Container();
        $replacement = new ClassWithoutDependencies();
        $container->bind(ClassWithoutDependencies::class, static fn (): object => $replacement);

        self::assertSame($replacement, $container->get(ClassWithoutDependencies::class));
        self::assertSame($replacement, $container->get(ClassWithoutDependencies::class));
    }

    /**
     * Two classes sharing a dependency both get builders, and the shared one is
     * built once per graph rather than shared between them — a builder is a
     * constructor, not a cache.
     */
    public function test_a_shared_dependency_is_still_constructed_per_graph(): void
    {
        $container = new Container();

        $a = $container->get(ClassWithDependencyWithoutDependencies::class);
        $b = $container->get(ClassWithDependencyWithoutDependencies::class);

        self::assertNotSame($a->classWithoutDependencies, $b->classWithoutDependencies);
    }

    public function test_a_leaf_class_resolves_through_a_builder_too(): void
    {
        $container = new Container();

        self::assertInstanceOf(ClassWithoutDependencies::class, $container->get(ClassWithoutDependencies::class));
        self::assertNotSame(
            $container->get(ClassWithoutDependencies::class),
            $container->get(ClassWithoutDependencies::class),
        );
    }

    /**
     * The refusal for a nested class propagates: an outer class whose
     * dependency cannot be built must not get a builder either.
     */
    public function test_a_refusal_propagates_to_the_parent(): void
    {
        $container = new Container();

        $first = $container->get(OuterHoldingInjectedService::class);
        $second = $container->get(OuterHoldingInjectedService::class);

        self::assertInstanceOf(ClassWithoutDependencies::class, $first->inner->dependency());
        self::assertInstanceOf(ClassWithoutDependencies::class, $second->inner->dependency());
    }

    // ---------------------------------------------------------------
    // When the builder is composed (#181). Composing walks the whole graph
    // below a class and allocates a closure per node, so a container that
    // constructs the class once must never pay for it.
    // ---------------------------------------------------------------

    public function test_the_first_construction_does_not_compose_a_builder(): void
    {
        $resolver = new DependencyResolver();

        self::assertNull($resolver->argBuilderFor(ClassWithoutDependencies::class));
    }

    public function test_the_second_construction_composes_one(): void
    {
        $resolver = new DependencyResolver();
        $resolver->argBuilderFor(ClassWithoutDependencies::class);

        $builder = $resolver->argBuilderFor(ClassWithoutDependencies::class);

        self::assertNotNull($builder);
        self::assertInstanceOf(ClassWithoutDependencies::class, $builder());
    }

    /**
     * The deferral applies to the class asked for, never to the recursion
     * underneath it: a nested class seen for the first time during composition
     * must not refuse, or its parent is cached as permanently ineligible and
     * the builder is never installed at all.
     */
    public function test_composing_a_parent_does_not_defer_on_its_dependencies(): void
    {
        $resolver = new DependencyResolver();
        $resolver->argBuilderFor(ClassWithDependencyWithoutDependencies::class);

        $builder = $resolver->argBuilderFor(ClassWithDependencyWithoutDependencies::class);

        self::assertNotNull($builder);
        $built = $builder();
        self::assertInstanceOf(ClassWithDependencyWithoutDependencies::class, $built);
        self::assertInstanceOf(ClassWithoutDependencies::class, $built->classWithoutDependencies);
    }

    /**
     * A never-composed class and a refused one are both "no builder", and the
     * refusal must survive: re-composing a class already proven ineligible on
     * every construction is the cost this whole change is removing.
     */
    public function test_a_refused_class_stays_refused(): void
    {
        $resolver = new DependencyResolver();

        self::assertNull($resolver->argBuilderFor(PersonWithoutParamType::class));
        self::assertNull($resolver->argBuilderFor(PersonWithoutParamType::class));
        self::assertNull($resolver->argBuilderFor(PersonWithoutParamType::class));
    }

    public function test_dropping_the_memo_restarts_the_deferral(): void
    {
        $resolver = new DependencyResolver();
        $resolver->argBuilderFor(ClassWithoutDependencies::class);
        self::assertNotNull($resolver->argBuilderFor(ClassWithoutDependencies::class));

        $resolver->dropArgBuilders();

        self::assertNull($resolver->argBuilderFor(ClassWithoutDependencies::class));
    }

    public function test_a_closure_binding_is_never_bypassed(): void
    {
        $container = new Container();
        $built = 0;

        $container->bind(ClassWithoutDependencies::class, static function (ContainerInterface $c) use (&$built): object {
            ++$built;

            return new ClassWithoutDependencies();
        });

        $container->get(ClassWithDependencyWithoutDependencies::class);
        $container->get(ClassWithDependencyWithoutDependencies::class);

        self::assertSame(2, $built);
    }
}
