<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\Exception\ContainerException;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\InMemoryRepository;
use GacelaTest\Fake\PersonWithoutDefaultValues;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AliasResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        Container::resetStaticCaches();
    }

    protected function tearDown(): void
    {
        Container::resetStaticCaches();
    }

    /**
     * The early return for "no aliases here": a scope that registered none of
     * its own still has to reach its parent's.
     */
    public function test_a_scope_with_no_aliases_resolves_its_parents(): void
    {
        $container = new Container();
        $container->alias('repo', InMemoryRepository::class);

        $scope = $container->createScope();

        self::assertInstanceOf(InMemoryRepository::class, $scope->get('repo'));
    }

    public function test_a_container_with_no_aliases_returns_the_id_unchanged(): void
    {
        $container = new Container();

        self::assertInstanceOf(
            ClassWithoutDependencies::class,
            $container->get(ClassWithoutDependencies::class),
        );
    }

    /**
     * The second resolution of the same alias comes from the cache, and has to
     * give the same answer as the first.
     */
    public function test_a_repeated_alias_resolves_to_the_same_id(): void
    {
        $container = new Container();
        $container->alias('repo', InMemoryRepository::class);
        $container->singleton(InMemoryRepository::class);

        self::assertSame($container->get('repo'), $container->get('repo'));
    }

    /**
     * The tail return: this registry has aliases, but not for this id — so the
     * answer comes from the parent rather than from the id itself.
     */
    public function test_an_id_this_scope_does_not_alias_falls_through_to_the_parent(): void
    {
        $container = new Container();
        $container->alias('repo', InMemoryRepository::class);

        $scope = $container->createScope();
        // The scope has an alias of its own, so it is past the early return —
        // and still has to defer to the parent for 'repo'.
        $scope->alias('other', DatabaseRepository::class);

        self::assertInstanceOf(InMemoryRepository::class, $scope->get('repo'));
        self::assertInstanceOf(DatabaseRepository::class, $scope->get('other'));
    }

    public function test_an_unaliased_id_is_returned_unchanged_even_with_aliases_present(): void
    {
        $container = new Container();
        $container->alias('repo', InMemoryRepository::class);

        self::assertInstanceOf(
            ClassWithoutDependencies::class,
            $container->get(ClassWithoutDependencies::class),
        );
    }

    public function test_adding_an_alias_invalidates_the_cached_answer(): void
    {
        $container = new Container();
        $container->alias('repo', InMemoryRepository::class);
        self::assertInstanceOf(InMemoryRepository::class, $container->get('repo'));

        $container->alias('repo', DatabaseRepository::class);

        self::assertInstanceOf(DatabaseRepository::class, $container->get('repo'));
    }
    public function test_alias_chains_resolve_for_get_and_has_on_repeated_calls(): void
    {
        $container = new Container();
        $instance = new ClassWithoutDependencies();
        $container->set('db', $instance);
        $container->alias('database', 'db');
        $container->alias('connection', 'database');

        for ($i = 0; $i < 3; ++$i) {
            self::assertTrue($container->has('connection'));
            self::assertSame($instance, $container->get('connection'));
        }
    }

    public function test_make_follows_alias_chains_with_runtime_parameters(): void
    {
        $container = new Container();
        $container->alias('person', PersonWithoutDefaultValues::class);
        $container->alias('current.person', 'person');

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame('Frodo', $container->make('current.person', ['name' => 'Frodo'])->name);
        }
    }

    public function test_loaded_alias_definitions_follow_chains(): void
    {
        $container = new Container();
        $instance = new ClassWithoutDependencies();
        $container->load([
            'connection' => ['alias' => 'database'],
            'database' => ['alias' => 'db'],
            'db' => ['value' => $instance],
        ]);

        self::assertTrue($container->has('connection'));
        self::assertSame($instance, $container->get('connection'));
    }

    public function test_changing_an_intermediate_alias_invalidates_cached_root_chains(): void
    {
        $container = new Container();
        $container->set('first', new ClassWithoutDependencies());
        $container->set('second', new ClassWithoutDependencies());
        $container->alias('connection', 'database');
        $container->alias('database', 'first');
        self::assertSame($container->get('first'), $container->get('connection'));
        $container->alias('database', 'second');

        self::assertSame($container->get('second'), $container->get('connection'));
    }

    public function test_an_inherited_alias_chain_reaches_a_scope_service_override(): void
    {
        $container = new Container();
        $parentValue = new ClassWithoutDependencies();
        $container->set('db', $parentValue);
        $container->alias('connection', 'database');
        $container->alias('database', 'db');
        $scope = $container->createScope();
        $scopeValue = new ClassWithoutDependencies();
        $scope->set('db', $scopeValue);

        self::assertTrue($scope->has('connection'));
        self::assertSame($scopeValue, $scope->get('connection'));
        self::assertSame($parentValue, $container->get('db'));
    }

    public function test_a_scope_alias_shadows_an_intermediate_link_in_a_cached_parent_chain(): void
    {
        $container = new Container();
        $container->set('db', new ClassWithoutDependencies());
        $container->alias('connection', 'database');
        $container->alias('database', 'db');
        self::assertSame($container->get('db'), $container->get('connection'));
        $scope = $container->createScope();
        $scopeValue = new ClassWithoutDependencies();
        $scope->set('scope.db', $scopeValue);
        $scope->alias('database', 'scope.db');

        self::assertSame($scopeValue, $scope->get('connection'));
        self::assertSame($container->get('db'), $container->get('connection'));
    }

    public function test_alias_chains_can_cross_multiple_scope_levels(): void
    {
        $container = new Container();
        $instance = new ClassWithoutDependencies();
        $container->set('db', $instance);
        $container->alias('database', 'db');
        $scope = $container->createScope();
        $scope->alias('connection', 'database');
        $nested = $scope->createScope();
        $nested->alias('current.connection', 'connection');

        self::assertTrue($nested->has('current.connection'));
        self::assertSame($instance, $nested->get('current.connection'));
    }

    public function test_alias_changes_in_a_parent_update_existing_scope_chains(): void
    {
        $container = new Container();
        $container->set('first', new ClassWithoutDependencies());
        $container->set('second', new ClassWithoutDependencies());
        $container->alias('database', 'first');
        $scope = $container->createScope();
        $scope->alias('connection', 'database');
        self::assertSame($container->get('first'), $scope->get('connection'));
        $container->alias('first', 'second');

        self::assertSame($container->get('second'), $scope->get('connection'));
    }

    #[DataProvider('resolutionMethodProvider')]
    public function test_a_local_alias_cycle_throws_a_container_exception(string $method): void
    {
        $container = new Container();
        $container->load([
            'first' => ['alias' => 'second'],
            'second' => ['alias' => 'first'],
        ]);
        $this->expectException(ContainerException::class);

        $container->{$method}('first');
    }

    #[DataProvider('resolutionMethodProvider')]
    public function test_an_alias_cycle_across_a_scope_and_parent_throws_a_container_exception(string $method): void
    {
        $container = new Container();
        $container->alias('first', 'second');
        $scope = $container->createScope();
        $scope->alias('second', 'first');
        $this->expectException(ContainerException::class);

        $scope->{$method}('first');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function resolutionMethodProvider(): iterable
    {
        yield 'get' => ['get'];
        yield 'has' => ['has'];
        yield 'make' => ['make'];
    }

    public function test_an_alias_pointing_to_itself_throws_a_container_exception(): void
    {
        $container = new Container();
        $container->alias('self', 'self');
        $this->expectException(ContainerException::class);

        $container->get('self');
    }

    public function test_a_late_parent_alias_cycle_is_not_hidden_by_a_previous_scope_result(): void
    {
        $container = new Container();
        $container->set('db', new ClassWithoutDependencies());
        $scope = $container->createScope();
        $scope->alias('connection', 'db');
        self::assertNotNull($scope->get('connection'));
        $container->alias('db', 'connection');
        $this->expectException(ContainerException::class);

        $scope->get('connection');
    }

    public function test_a_scope_override_can_break_a_parent_alias_cycle(): void
    {
        $container = new Container();
        $container->alias('first', 'second');
        $container->alias('second', 'first');
        $scope = $container->createScope();
        $instance = new ClassWithoutDependencies();
        $scope->set('db', $instance);
        $scope->alias('second', 'db');

        self::assertSame($instance, $scope->get('first'));
    }

}
