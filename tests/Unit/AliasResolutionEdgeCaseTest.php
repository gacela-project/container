<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\RepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Alias resolution is short-circuited when no aliases are registered, and
 * memoized when they are. Both paths must agree.
 */
final class AliasResolutionEdgeCaseTest extends TestCase
{
    public function test_repeated_resolution_through_an_alias_is_stable(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, DatabaseRepository::class);
        $container->alias('repo', RepositoryInterface::class);

        $first = $container->get('repo');
        $second = $container->get('repo');

        self::assertInstanceOf(DatabaseRepository::class, $first);
        self::assertInstanceOf(DatabaseRepository::class, $second);
    }

    public function test_an_alias_registered_after_first_use_still_takes_effect(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, DatabaseRepository::class);

        // Resolve the id directly first, priming any resolution cache.
        self::assertInstanceOf(DatabaseRepository::class, $container->get(RepositoryInterface::class));

        $container->alias('repo', RepositoryInterface::class);

        self::assertTrue($container->has('repo'));
        self::assertInstanceOf(DatabaseRepository::class, $container->get('repo'));
    }

    public function test_an_alias_added_after_another_alias_invalidates_the_cache(): void
    {
        $container = new Container();
        $container->set('first', new ClassWithoutDependencies());
        $container->set('second', new ClassWithoutDependencies());

        $container->alias('a', 'first');
        self::assertSame($container->get('first'), $container->get('a'));

        $container->alias('b', 'second');

        self::assertSame($container->get('first'), $container->get('a'));
        self::assertSame($container->get('second'), $container->get('b'));
    }

    public function test_an_unaliased_id_resolves_to_itself(): void
    {
        $container = new Container();
        $instance = new ClassWithoutDependencies();
        $container->set('plain', $instance);

        self::assertSame($instance, $container->get('plain'));
        self::assertSame($instance, $container->get('plain'));
    }

    public function test_aliasing_does_not_leak_between_containers(): void
    {
        $first = new Container();
        $first->set('service', new ClassWithoutDependencies());
        $first->alias('shortcut', 'service');

        $second = new Container();

        self::assertTrue($first->has('shortcut'));
        self::assertFalse($second->has('shortcut'));
    }
}
