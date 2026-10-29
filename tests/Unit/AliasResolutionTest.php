<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use ArrayObject;
use Gacela\Container\Container;
use Gacela\Container\Exception\ContainerException;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\InMemoryRepository;
use GacelaTest\Fake\PersonWithoutDefaultValues;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\ServiceWithRepository;
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

    #[DataProvider('aliasWriteMethods')]
    public function test_writing_through_an_alias_chain_updates_only_the_canonical_registration(bool $arrayWrite): void
    {
        $container = new Container();
        $container->set('db', 'primary');
        $container->alias('connection', 'database');
        $container->alias('database', 'db');

        self::writeAlias($container, 'connection', 'replica', $arrayWrite);

        self::assertSame(['db'], $container->getRegisteredServices());
        self::assertSame('replica', $container->get('connection'));
        self::assertSame('replica', $container->get('db'));
        $container->remove('connection');
        self::assertSame([], $container->getRegisteredServices());
        self::assertFalse($container->has('connection'));
    }

    #[DataProvider('aliasWriteMethods')]
    public function test_writing_through_an_alias_cannot_bypass_freezing(bool $arrayWrite): void
    {
        $container = new Container();
        $container->set('db', 'primary');
        $container->alias('database', 'db');
        self::assertSame('primary', $container->get('database'));

        try {
            self::writeAlias($container, 'database', 'replica', $arrayWrite);
            self::fail('A frozen canonical service cannot be overwritten');
        } catch (ContainerException) {
        }

        self::assertSame(['db'], $container->getRegisteredServices());
        self::assertSame('primary', $container->get('database'));
    }

    #[DataProvider('aliasWriteMethods')]
    public function test_writing_through_an_alias_applies_scheduled_extensions(bool $arrayWrite): void
    {
        $container = new Container();
        $container->alias('connection', 'database');
        $container->alias('database', 'db');
        $container->extend('connection', static fn (object $service): ArrayObject => new ArrayObject(['wrapped' => $service]));
        $service = new ClassWithoutDependencies();

        self::writeAlias($container, 'connection', $service, $arrayWrite);
        $extended = $container->get('db');

        self::assertInstanceOf(ArrayObject::class, $extended);
        self::assertSame($service, $extended['wrapped']);
        self::assertSame($extended, $container->get('connection'));
        self::assertSame(['db'], $container->getRegisteredServices());
    }

    #[DataProvider('aliasWriteMethods')]
    public function test_scope_writes_through_inherited_aliases_shadow_the_canonical_id_locally(bool $arrayWrite): void
    {
        $parent = new Container();
        $parent->set('db', 'parent');
        $parent->alias('connection', 'database');
        $parent->alias('database', 'db');
        self::assertSame('parent', $parent->get('connection'));
        $scope = $parent->createScope();

        self::writeAlias($scope, 'connection', 'scope', $arrayWrite);

        self::assertSame('scope', $scope->get('connection'));
        self::assertSame('scope', $scope->get('db'));
        self::assertSame('parent', $parent->get('db'));
        self::assertSame(['db'], $scope->getRegisteredServices());
        $scope->remove('connection');
        self::assertSame('parent', $scope->get('connection'));
        self::assertSame(['db'], $scope->getRegisteredServices());
    }

    #[DataProvider('aliasWriteMethods')]
    public function test_scope_writes_honor_a_local_override_of_an_inherited_alias_link(bool $arrayWrite): void
    {
        $parent = new Container();
        $parent->set('db', 'parent');
        $parent->alias('connection', 'database');
        $parent->alias('database', 'db');
        self::assertSame('parent', $parent->get('connection'));
        $scope = $parent->createScope();
        $scope->alias('database', 'scope.db');

        self::writeAlias($scope, 'connection', 'scope', $arrayWrite);

        self::assertSame('scope', $scope->get('scope.db'));
        self::assertSame('scope', $scope->get('connection'));
        self::assertSame('parent', $parent->get('connection'));
        self::assertSame(['db', 'scope.db'], $scope->getRegisteredServices());
    }

    #[DataProvider('aliasWriteMethods')]
    public function test_an_alias_write_is_visible_to_a_typed_constructor_parameter(bool $arrayWrite): void
    {
        $container = new Container();
        $container->alias(RepositoryInterface::class, 'repository');
        $repository = new InMemoryRepository();

        self::writeAlias($container, RepositoryInterface::class, $repository, $arrayWrite);
        $service = $container->get(ServiceWithRepository::class);

        self::assertInstanceOf(ServiceWithRepository::class, $service);
        self::assertSame($repository, $service->repository);
        self::assertSame(['repository'], $container->getRegisteredServices());
    }

    #[DataProvider('aliasWriteMethods')]
    public function test_an_alias_cycle_rejects_writes_without_creating_a_registration(bool $arrayWrite): void
    {
        $container = new Container();
        $container->alias('first', 'second');
        $container->alias('second', 'first');

        try {
            self::writeAlias($container, 'first', 'value', $arrayWrite);
            self::fail('An alias cycle must reject the write');
        } catch (ContainerException) {
        }

        self::assertSame([], $container->getRegisteredServices());
    }

    public function test_an_integer_array_write_follows_its_string_alias(): void
    {
        $container = new Container();
        $container->alias('1', 'db');

        $container[1] = 'value';

        self::assertSame('value', $container->get('db'));
        self::assertSame('value', $container[1]);
        self::assertSame(['db'], $container->getRegisteredServices());
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function aliasWriteMethods(): iterable
    {
        yield 'set' => [false];
        yield 'array assignment' => [true];
    }

    private static function writeAlias(Container $container, string $id, mixed $value, bool $arrayWrite): void
    {
        if ($arrayWrite) {
            $container[$id] = $value;
        } else {
            $container->set($id, $value);
        }
    }
}
