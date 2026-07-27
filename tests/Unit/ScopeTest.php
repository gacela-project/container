<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use ArrayObject;
use Gacela\Container\Container;
use Gacela\Container\ContainerInterface;
use Gacela\Container\Exception\ContainerException;
use Gacela\Container\Exception\DependencyNotFoundException;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\InMemoryRepository;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\PersonInterface;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\ServiceWithRepository;
use GacelaTest\Fake\SingletonAttributeService;
use PHPUnit\Framework\TestCase;
use stdClass;
use WeakReference;

use function iterator_to_array;

final class ScopeTest extends TestCase
{
    public function test_scope_resolves_what_the_parent_registered(): void
    {
        $container = new Container();
        $container->set('config', 'from-parent');

        $scope = $container->createScope();

        self::assertSame('from-parent', $scope->get('config'));
    }

    public function test_scope_resolves_a_parent_binding_as_a_nested_dependency(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, InMemoryRepository::class);

        $scope = $container->createScope();

        self::assertInstanceOf(
            InMemoryRepository::class,
            $scope->make(ServiceWithRepository::class)->repository,
        );
    }

    /**
     * get() consults the scope's own binding before delegating. Without that
     * guard the parent answers first and the shadow never applies.
     */
    public function test_get_prefers_the_scope_binding_over_the_parent(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, DatabaseRepository::class);

        $scope = $container->createScope();
        $scope->bind(RepositoryInterface::class, InMemoryRepository::class);

        self::assertInstanceOf(InMemoryRepository::class, $scope->get(RepositoryInterface::class));
        self::assertInstanceOf(DatabaseRepository::class, $container->get(RepositoryInterface::class));
    }

    public function test_scope_registration_shadows_the_parent_for_nested_dependencies(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, DatabaseRepository::class);

        $scope = $container->createScope();
        $scope->bind(RepositoryInterface::class, InMemoryRepository::class);

        self::assertInstanceOf(
            InMemoryRepository::class,
            $scope->make(ServiceWithRepository::class)->repository,
        );
        self::assertInstanceOf(
            DatabaseRepository::class,
            $container->make(ServiceWithRepository::class)->repository,
        );
    }

    public function test_scope_instance_shadows_the_parent_without_mutating_it(): void
    {
        $container = new Container();
        $container->set('config', 'from-parent');

        $scope = $container->createScope();
        $scope->set('config', 'from-scope');

        self::assertSame('from-scope', $scope->get('config'));
        self::assertSame('from-parent', $container->get('config'));
        self::assertSame(['config'], $container->getRegisteredServices());
    }

    /**
     * A frozen instance cannot be replaced on the container holding it, but a
     * scope stores its own copy, so freezing upstream does not reach it.
     */
    public function test_scope_can_shadow_an_id_the_parent_has_already_frozen(): void
    {
        $container = new Container();
        $container->set('config', 'from-parent');
        $container->get('config');
        self::assertTrue($container->isFrozen('config'));

        $scope = $container->createScope();
        $scope->set('config', 'from-scope');

        self::assertFalse($scope->isFrozen('config'));
        self::assertSame('from-scope', $scope->get('config'));
    }

    public function test_frozen_state_of_a_shadowed_id_is_the_scope_own(): void
    {
        $container = new Container();
        $container->set('config', static fn (): stdClass => new stdClass());
        $container->get('config');

        $scope = $container->createScope();
        $scope->set('config', static fn (): stdClass => new stdClass());

        self::assertFalse($scope->isFrozen('config'));

        $scope->get('config');

        self::assertTrue($scope->isFrozen('config'));
    }

    public function test_a_binding_registered_after_the_scope_exists_is_still_visible(): void
    {
        $container = new Container();
        $scope = $container->createScope();
        $scope->get(ClassWithoutDependencies::class);

        $container->bind(RepositoryInterface::class, InMemoryRepository::class);

        self::assertInstanceOf(
            InMemoryRepository::class,
            $scope->make(ServiceWithRepository::class)->repository,
        );
    }

    public function test_singleton_resolved_by_the_parent_is_shared_by_every_scope(): void
    {
        $container = new Container();
        $shared = $container->get(SingletonAttributeService::class);

        $one = $container->createScope();
        $other = $container->createScope();

        self::assertSame($shared, $one->get(SingletonAttributeService::class));
        self::assertSame($shared, $other->get(SingletonAttributeService::class));
    }

    /**
     * singleton() marks the *concrete* class, which no binding names when the
     * abstract differs. Asking for the concrete directly still has to reach the
     * container that owns it.
     */
    public function test_singleton_bound_under_an_abstract_is_owned_by_the_parent(): void
    {
        $container = new Container();
        $container->singleton(RepositoryInterface::class, InMemoryRepository::class);

        $scope = $container->createScope();

        self::assertTrue($container->provides(InMemoryRepository::class));
        self::assertSame(
            $container->get(InMemoryRepository::class),
            $scope->get(InMemoryRepository::class),
        );
    }

    public function test_singleton_first_resolved_in_a_scope_belongs_to_that_scope(): void
    {
        $container = new Container();

        $one = $container->createScope();
        $other = $container->createScope();

        $inOne = $one->get(SingletonAttributeService::class);

        self::assertSame($inOne, $one->get(SingletonAttributeService::class));
        self::assertNotSame($inOne, $other->get(SingletonAttributeService::class));
        self::assertNotSame($inOne, $container->get(SingletonAttributeService::class));
    }

    /**
     * The scope owns it first, so the parent later acquiring one of its own
     * must not start answering for the scope.
     */
    public function test_a_scope_keeps_its_own_singleton_after_the_parent_resolves_one(): void
    {
        $container = new Container();
        $scope = $container->createScope();

        $inScope = $scope->get(SingletonAttributeService::class);
        $inParent = $container->get(SingletonAttributeService::class);

        self::assertNotSame($inScope, $inParent);
        self::assertSame($inScope, $scope->get(SingletonAttributeService::class));
    }

    public function test_a_parent_singleton_is_shared_when_reached_as_a_nested_dependency(): void
    {
        $container = new Container();
        $container->singleton(RepositoryInterface::class, InMemoryRepository::class);
        $shared = $container->get(RepositoryInterface::class);

        $scope = $container->createScope();

        self::assertSame($shared, $scope->make(ServiceWithRepository::class)->repository);
    }

    public function test_what_a_scope_resolved_is_released_with_the_scope(): void
    {
        $container = new Container();
        $scope = $container->createScope();
        $scope->set('request', new stdClass());

        $reference = WeakReference::create($scope->get('request'));
        $scope = null;

        // A container holds cycles back to itself, so refcounting alone never
        // frees one; what matters here is that the parent adds no reference of
        // its own that would survive the collection.
        gc_collect_cycles();

        self::assertNull($reference->get());
    }

    public function test_scopes_nest(): void
    {
        $container = new Container();
        $container->set('config', 'from-parent');

        $scope = $container->createScope();
        $scope->set('scoped', 'from-scope');
        $nested = $scope->createScope();

        self::assertSame('from-parent', $nested->get('config'));
        self::assertSame('from-scope', $nested->get('scoped'));
    }

    public function test_scope_inherits_aliases(): void
    {
        $container = new Container();
        $container->set('db', 'the-db');
        $container->alias('database', 'db');

        $scope = $container->createScope();

        self::assertSame('the-db', $scope->get('database'));
        self::assertTrue($scope->has('database'));
    }

    /**
     * An inherited alias has to be applied before the scope's own registries
     * are consulted, or the aliased name and the canonical one disagree.
     */
    public function test_an_inherited_alias_still_finds_the_scope_shadow(): void
    {
        $container = new Container();
        $container->set('db', 'from-parent');
        $container->alias('database', 'db');

        $scope = $container->createScope();
        $scope->set('db', 'from-scope');

        self::assertSame('from-scope', $scope->get('db'));
        self::assertSame('from-scope', $scope->get('database'));
        self::assertSame('from-parent', $container->get('database'));
    }

    public function test_an_inherited_alias_reaches_a_scope_binding(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, DatabaseRepository::class);
        $container->alias('repo', RepositoryInterface::class);

        $scope = $container->createScope();
        $scope->bind(RepositoryInterface::class, InMemoryRepository::class);

        self::assertInstanceOf(InMemoryRepository::class, $scope->get('repo'));
        self::assertInstanceOf(DatabaseRepository::class, $container->get('repo'));
    }

    public function test_a_scope_alias_wins_over_the_inherited_one(): void
    {
        $container = new Container();
        $container->set('primary', 'from-parent');
        $container->set('secondary', 'the-other-one');
        $container->alias('db', 'primary');

        $scope = $container->createScope();
        $scope->alias('db', 'secondary');

        self::assertSame('the-other-one', $scope->get('db'));
        self::assertSame('from-parent', $container->get('db'));
    }

    public function test_scope_inherits_contextual_bindings_defined_before_it_was_created(): void
    {
        $container = new Container();
        $container->when(ServiceWithRepository::class)
            ->needs(RepositoryInterface::class)
            ->give(InMemoryRepository::class);

        $scope = $container->createScope();

        self::assertInstanceOf(
            InMemoryRepository::class,
            $scope->make(ServiceWithRepository::class)->repository,
        );
    }

    public function test_contextual_binding_on_a_scope_does_not_reach_the_parent(): void
    {
        $container = new Container();
        $scope = $container->createScope();
        $scope->when(ServiceWithRepository::class)
            ->needs(RepositoryInterface::class)
            ->give(InMemoryRepository::class);

        self::assertInstanceOf(
            InMemoryRepository::class,
            $scope->make(ServiceWithRepository::class)->repository,
        );

        $this->expectException(DependencyNotFoundException::class);
        $container->make(ServiceWithRepository::class);
    }

    public function test_scope_appends_to_an_inherited_tag(): void
    {
        $container = new Container();
        $container->set('first', 'A');
        $container->tag('first', 'letters');

        $scope = $container->createScope();
        $scope->set('second', 'B');
        $scope->tag('second', 'letters');

        self::assertSame(['A', 'B'], iterator_to_array($scope->tagged('letters'), false));
        self::assertSame(['A'], iterator_to_array($container->tagged('letters'), false));
    }

    public function test_an_id_tagged_on_both_levels_is_resolved_once(): void
    {
        $container = new Container();
        $container->set('shared', 'A');
        $container->tag('shared', 'letters');

        $scope = $container->createScope();
        $scope->tag('shared', 'letters');

        self::assertSame(['A'], iterator_to_array($scope->tagged('letters'), false));
    }

    public function test_bound_and_has_see_the_parent(): void
    {
        $container = new Container();
        $container->set('config', 'from-parent');
        $container->bind(RepositoryInterface::class, InMemoryRepository::class);

        $scope = $container->createScope();

        self::assertTrue($scope->bound('config'));
        self::assertTrue($scope->bound(RepositoryInterface::class));
        self::assertTrue($scope->has(RepositoryInterface::class));
        self::assertFalse($scope->bound('nothing-registered'));
        self::assertFalse($scope->has('nothing-registered'));
    }

    public function test_bind_if_and_singleton_if_treat_an_inherited_binding_as_bound(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, DatabaseRepository::class);

        $scope = $container->createScope();
        $scope->bindIf(RepositoryInterface::class, InMemoryRepository::class);
        $scope->singletonIf(PersonInterface::class, Person::class);

        self::assertInstanceOf(DatabaseRepository::class, $scope->get(RepositoryInterface::class));
        self::assertInstanceOf(Person::class, $scope->get(PersonInterface::class));
    }

    public function test_provides_sits_between_bound_and_has(): void
    {
        $container = new Container();
        $container->get(SingletonAttributeService::class);
        $scope = $container->createScope();

        // bound() only knows registrations, and nothing registered this one.
        self::assertFalse($scope->bound(SingletonAttributeService::class));
        self::assertTrue($scope->provides(SingletonAttributeService::class));

        // has() is true of anything autowirable, owned or not.
        self::assertTrue($scope->has(ClassWithoutDependencies::class));
        self::assertFalse($scope->provides(ClassWithoutDependencies::class));
    }

    public function test_get_bindings_merges_the_chain_with_the_scope_winning(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, DatabaseRepository::class);
        $container->bind(PersonInterface::class, Person::class);

        $scope = $container->createScope();
        $scope->bind(RepositoryInterface::class, InMemoryRepository::class);

        self::assertSame([
            RepositoryInterface::class => InMemoryRepository::class,
            PersonInterface::class => Person::class,
        ], $scope->getBindings());
    }

    public function test_registered_services_merge_the_chain_without_duplicates(): void
    {
        $container = new Container();
        $container->set('config', 'from-parent');
        $container->set('logger', 'the-logger');

        $scope = $container->createScope();
        $scope->set('config', 'from-scope');
        $scope->set('request', 'the-request');

        self::assertSame(['config', 'logger', 'request'], $scope->getRegisteredServices());
    }

    public function test_factory_and_frozen_state_are_read_from_the_owning_container(): void
    {
        $container = new Container();
        $container->set('fresh', $container->factory(static fn (): stdClass => new stdClass()));
        $container->set('config', 'from-parent');
        $container->get('config');

        $scope = $container->createScope();

        self::assertTrue($scope->isFactory('fresh'));
        self::assertNotSame($scope->get('fresh'), $scope->get('fresh'));
        self::assertTrue($scope->isFrozen('config'));
        self::assertFalse($scope->isFactory('config'));
        self::assertFalse($scope->isFrozen('never-registered'));
    }

    public function test_protected_closure_from_the_parent_is_returned_unresolved(): void
    {
        $container = new Container();
        $guard = static fn (): string => 'not-invoked';
        $container->set('guard', $container->protect($guard));

        $scope = $container->createScope();

        self::assertSame($guard, $scope->get('guard'));
    }

    public function test_a_closure_registered_on_the_parent_receives_the_parent(): void
    {
        $container = new Container();
        $container->set('who', static fn (ContainerInterface $c): ContainerInterface => $c);

        $scope = $container->createScope();

        self::assertSame($container, $scope->get('who'));
    }

    public function test_array_access_on_a_scope_follows_the_chain(): void
    {
        $container = new Container();
        $container->set('config', 'from-parent');

        $scope = $container->createScope();

        self::assertTrue(isset($scope['config']));
        self::assertSame('from-parent', $scope['config']);

        $scope['config'] = 'from-scope';
        self::assertSame('from-scope', $scope['config']);

        unset($scope['config']);
        self::assertSame('from-parent', $scope['config']);
        self::assertSame('from-parent', $container['config']);
    }

    public function test_get_or_fail_and_resolve_follow_the_chain(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, InMemoryRepository::class);

        $scope = $container->createScope();

        self::assertInstanceOf(InMemoryRepository::class, $scope->getOrFail(RepositoryInterface::class));
        self::assertInstanceOf(
            InMemoryRepository::class,
            $scope->resolve(static fn (RepositoryInterface $repository): RepositoryInterface => $repository),
        );
    }

    public function test_after_resolving_fires_on_the_container_that_resolves(): void
    {
        $container = new Container();
        $container->set('config', 'from-parent');

        $fired = [];
        $container->afterResolving('config', static function () use (&$fired): void {
            $fired[] = 'parent';
        });

        $scope = $container->createScope();
        $scope->afterResolving('config', static function () use (&$fired): void {
            $fired[] = 'scope';
        });

        $scope->get('config');

        self::assertSame(['parent', 'scope'], $fired);
    }

    public function test_remove_only_forgets_what_the_scope_stored(): void
    {
        $container = new Container();
        $container->set('config', 'from-parent');

        $scope = $container->createScope();
        $scope->set('config', 'from-scope');
        $scope->remove('config');

        self::assertSame('from-parent', $scope->get('config'));
        self::assertSame('from-parent', $container->get('config'));
    }

    /**
     * @return list<array{callable(Container): void}>
     */
    public static function inheritedOwnershipProvider(): array
    {
        return [
            'stored instance' => [static function (Container $c): void {
                $c->set('service', new ClassWithoutDependencies());
            }],
            'closure binding' => [static function (Container $c): void {
                $c->bind('service', static fn (): ClassWithoutDependencies => new ClassWithoutDependencies());
            }],
            'class binding' => [static function (Container $c): void {
                $c->bind('service', ClassWithoutDependencies::class);
            }],
            'resolved singleton' => [static function (Container $c): void {
                $c->singleton('service', ClassWithoutDependencies::class);
                $c->get('service');
            }],
        ];
    }

    /**
     * Every shape of ownership has to throw, not just a stored instance: an id
     * an ancestor owns is resolved by that ancestor, so an extension scheduled
     * on the scope would never run.
     *
     * @param callable(Container): void $registerOnParent
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('inheritedOwnershipProvider')]
    public function test_extending_something_an_ancestor_owns_throws(callable $registerOnParent): void
    {
        $container = new Container();
        $registerOnParent($container);

        $scope = $container->createScope();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("The instance 'service' belongs to a parent container");
        $scope->extend('service', static fn (object $service): object => $service);
    }

    public function test_extending_something_a_grandparent_owns_throws(): void
    {
        $container = new Container();
        $container->set('service', new ClassWithoutDependencies());

        $nested = $container->createScope()->createScope();

        $this->expectException(ContainerException::class);
        $nested->extend('service', static fn (object $service): object => $service);
    }

    public function test_extending_an_id_nobody_owns_still_schedules_on_the_scope(): void
    {
        $container = new Container();
        $scope = $container->createScope();

        $scope->extend('service', static fn (object $service): ArrayObject => new ArrayObject(['extended']));
        $scope->set('service', new ClassWithoutDependencies());

        self::assertInstanceOf(ArrayObject::class, $scope->get('service'));
    }

    public function test_compiled_plans_are_shared_across_the_chain(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, InMemoryRepository::class);
        $container->warmUp([ServiceWithRepository::class]);

        $scope = $container->createScope();
        self::assertArrayHasKey(ServiceWithRepository::class, $scope->compile([]));

        $scope->warmUp([ClassWithoutDependencies::class]);
        self::assertArrayHasKey(ClassWithoutDependencies::class, $container->compile([]));
    }

    public function test_a_scope_created_before_the_parent_warms_up_still_sees_its_plans(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, InMemoryRepository::class);
        $scope = $container->createScope();

        self::assertArrayNotHasKey(ServiceWithRepository::class, $scope->compile([]));

        $container->warmUp([ServiceWithRepository::class]);

        self::assertArrayHasKey(ServiceWithRepository::class, $scope->compile([]));
    }

    public function test_a_scope_uses_the_compiled_factories_installed_on_the_parent(): void
    {
        $container = new Container();
        $calls = 0;
        $container->useCompiledFactories([
            ClassWithoutDependencies::class => static function () use (&$calls): ClassWithoutDependencies {
                ++$calls;
                return new ClassWithoutDependencies();
            },
        ]);

        $container->createScope()->get(ClassWithoutDependencies::class);

        self::assertSame(1, $calls);
    }

    public function test_write_compiled_factories_on_a_scope_sees_inherited_bindings(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'gacela-scope-factories');
        self::assertIsString($file);

        $container = new Container();
        // A bound abstract could be rebound later, so it must never compile —
        // the scope has to see the parent's bindings to know that.
        $container->bind(InMemoryRepository::class, InMemoryRepository::class);

        $compiled = $container->createScope()->writeCompiledFactories([InMemoryRepository::class], $file);

        unlink($file);
        self::assertNotContains(InMemoryRepository::class, $compiled);
    }

    public function test_dependency_tree_follows_inherited_bindings(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, InMemoryRepository::class);

        $scope = $container->createScope();

        self::assertSame([InMemoryRepository::class], $scope->getDependencyTree(ServiceWithRepository::class));
    }

    public function test_stats_report_the_whole_chain(): void
    {
        $container = new Container();
        $container->set('config', 'from-parent');
        $container->bind(PersonInterface::class, Person::class);

        $scope = $container->createScope();
        $scope->set('request', 'the-request');
        $scope->bind(RepositoryInterface::class, InMemoryRepository::class);

        self::assertSame(2, $scope->stats()->registeredServices);
        self::assertSame(2, $scope->stats()->bindings);
        self::assertSame(2, $scope->getStats()['registered_services']);
        self::assertSame(1, $container->stats()->registeredServices);
        self::assertSame(1, $container->stats()->bindings);
    }

    public function test_unresolvable_abstract_suggests_bindings_from_the_whole_chain(): void
    {
        $container = new Container();
        $container->bind('GacelaTest\\Fake\\RepositoryInterfce', InMemoryRepository::class);

        $scope = $container->createScope();
        $scope->bind('GacelaTest\\Fake\\SomethingElse', InMemoryRepository::class);

        try {
            $scope->make(ServiceWithRepository::class);
            self::fail('Expected DependencyNotFoundException to be thrown');
        } catch (DependencyNotFoundException $e) {
            self::assertStringContainsString('GacelaTest\\Fake\\RepositoryInterfce', $e->getMessage());
        }
    }
}
