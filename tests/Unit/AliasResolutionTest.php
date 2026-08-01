<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\InMemoryRepository;
use PHPUnit\Framework\TestCase;

/**
 * The three branches of alias resolution, each reachable only through a
 * particular shape: no aliases at all, a cached answer, and an id this registry
 * does not map but an ancestor does.
 */
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
}
