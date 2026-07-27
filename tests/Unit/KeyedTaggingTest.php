<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\Exception\ContainerException;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\ConstructionCounter;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\ExpensiveReportGenerator;
use GacelaTest\Fake\InMemoryRepository;
use GacelaTest\Fake\Person;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function iterator_to_array;

/**
 * A tag as a lookup table: "give me the handler for 'email'", which is what a
 * command bus, a router or a strategy map asks for. The lookup must not become
 * a second place instances live — the container already has one.
 */
final class KeyedTaggingTest extends TestCase
{
    protected function setUp(): void
    {
        ConstructionCounter::reset();
    }

    public function test_a_keyed_entry_is_resolved_by_its_key(): void
    {
        $container = new Container();
        $container->tag([
            'memory' => InMemoryRepository::class,
            'database' => DatabaseRepository::class,
        ], 'repositories');

        self::assertInstanceOf(InMemoryRepository::class, $container->taggedByKey('repositories', 'memory'));
        self::assertInstanceOf(DatabaseRepository::class, $container->taggedByKey('repositories', 'database'));
    }

    public function test_only_the_asked_for_entry_is_built(): void
    {
        $container = new Container();
        $container->tag([
            'cheap' => ClassWithoutDependencies::class,
            'expensive' => ExpensiveReportGenerator::class,
        ], 'handlers');

        $container->taggedByKey('handlers', 'cheap');

        // Registering a hundred handlers must build none of them, and asking
        // for one must build one.
        self::assertSame(0, ConstructionCounter::countFor(ExpensiveReportGenerator::class));
    }

    public function test_the_instance_comes_from_the_containers_own_cache(): void
    {
        $container = new Container();
        $container->singleton(InMemoryRepository::class);
        $container->tag(['memory' => InMemoryRepository::class], 'repositories');

        // Not a cache in front of the cache: the singleton the container holds
        // is the one the tag hands back.
        self::assertSame(
            $container->get(InMemoryRepository::class),
            $container->taggedByKey('repositories', 'memory'),
        );
    }

    public function test_keys_and_plain_ids_live_in_one_tag(): void
    {
        $container = new Container();
        $container->tag([Person::class], 'mixed');
        $container->tag(['config' => ClassWithoutDependencies::class], 'mixed');

        $resolved = iterator_to_array($container->tagged('mixed'));

        self::assertInstanceOf(Person::class, $resolved[0]);
        self::assertInstanceOf(ClassWithoutDependencies::class, $resolved['config']);
    }

    public function test_tagged_still_yields_positions_for_unkeyed_entries(): void
    {
        $container = new Container();
        $container->tag([ClassWithoutDependencies::class, Person::class], 'group');

        // The pre-existing contract: an unkeyed tag iterates 0..n.
        self::assertSame([0, 1], array_keys(iterator_to_array($container->tagged('group'))));
    }

    public function test_a_key_registered_twice_keeps_the_last_id(): void
    {
        $container = new Container();
        $container->tag(['repository' => InMemoryRepository::class], 'services');
        $container->tag(['repository' => DatabaseRepository::class], 'services');

        // Layering per environment is registration order, the way definitions
        // already behave.
        self::assertInstanceOf(DatabaseRepository::class, $container->taggedByKey('services', 'repository'));
        self::assertSame(['repository'], $container->taggedKeys('services'));
    }

    public function test_the_keys_of_a_tag_can_be_listed(): void
    {
        $container = new Container();
        $container->tag([
            'memory' => InMemoryRepository::class,
            'database' => DatabaseRepository::class,
        ], 'repositories');
        $container->tag(Person::class, 'repositories');

        // An unkeyed entry has no key to ask with, so it is not listed.
        self::assertSame(['memory', 'database'], $container->taggedKeys('repositories'));
        self::assertSame([], $container->taggedKeys('unknown-tag'));
    }

    public function test_an_unknown_key_throws_naming_the_keys_that_exist(): void
    {
        $container = new Container();
        $container->tag(['email' => ClassWithoutDependencies::class], 'handlers');

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches("/has no entry keyed 'sms'/");
        $this->expectExceptionMessageMatches('/- email/');

        $container->taggedByKey('handlers', 'sms');
    }

    public function test_a_near_miss_key_is_suggested(): void
    {
        $container = new Container();
        $container->tag(['notification' => ClassWithoutDependencies::class], 'handlers');

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/Did you mean one of these\?/');

        $container->taggedByKey('handlers', 'notifcation');
    }

    public function test_a_tag_with_no_keys_at_all_says_how_to_register_one(): void
    {
        $container = new Container();
        $container->tag(ClassWithoutDependencies::class, 'handlers');

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/Register one with a map/');

        $container->taggedByKey('handlers', 'email');
    }

    public function test_an_unknown_tag_throws_too(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);

        $container->taggedByKey('nothing-registered', 'email');
    }

    public function test_a_scope_inherits_keyed_entries(): void
    {
        $container = new Container();
        $container->tag(['memory' => InMemoryRepository::class], 'repositories');

        $scope = $container->createScope();

        self::assertInstanceOf(InMemoryRepository::class, $scope->taggedByKey('repositories', 'memory'));
        self::assertSame(['memory'], $scope->taggedKeys('repositories'));
    }

    public function test_a_scope_can_override_an_inherited_key_without_touching_the_parent(): void
    {
        $container = new Container();
        $container->tag(['repository' => InMemoryRepository::class], 'services');

        $scope = $container->createScope();
        $scope->tag(['repository' => DatabaseRepository::class], 'services');

        self::assertInstanceOf(DatabaseRepository::class, $scope->taggedByKey('services', 'repository'));
        self::assertInstanceOf(InMemoryRepository::class, $container->taggedByKey('services', 'repository'));
    }

    public function test_a_scope_appends_unkeyed_ids_after_the_inherited_ones(): void
    {
        $container = new Container();
        $container->tag(ClassWithoutDependencies::class, 'group');

        $scope = $container->createScope();
        $scope->tag(Person::class, 'group');

        $resolved = iterator_to_array($scope->tagged('group'));

        self::assertInstanceOf(ClassWithoutDependencies::class, $resolved[0]);
        self::assertInstanceOf(Person::class, $resolved[1]);
    }
}
