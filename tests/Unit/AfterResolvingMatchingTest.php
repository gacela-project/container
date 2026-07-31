<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\ContainerInterface;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\InMemoryRepository;
use GacelaTest\Fake\PersonWithoutDefaultValues;
use GacelaTest\Fake\RepositoryInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The registration people actually want: "after anything implementing X is
 * built, do this" — one call, not one per implementation.
 */
final class AfterResolvingMatchingTest extends TestCase
{
    protected function setUp(): void
    {
        Container::resetStaticCaches();
    }

    protected function tearDown(): void
    {
        Container::resetStaticCaches();
    }

    public function test_an_interface_hook_fires_for_every_implementation(): void
    {
        $container = new Container();
        $seen = [];

        $container->afterResolving(
            RepositoryInterface::class,
            static function (object $instance) use (&$seen): void {
                $seen[] = $instance::class;
            },
        );

        $container->get(DatabaseRepository::class);
        $container->get(InMemoryRepository::class);

        self::assertSame([DatabaseRepository::class, InMemoryRepository::class], $seen);
    }

    public function test_a_class_hook_fires_for_that_class(): void
    {
        $container = new Container();
        $fired = false;

        $container->afterResolving(
            ClassWithoutDependencies::class,
            static function () use (&$fired): void {
                $fired = true;
            },
        );
        $container->get(ClassWithoutDependencies::class);

        self::assertTrue($fired);
    }

    public function test_a_hook_does_not_fire_for_an_unrelated_type(): void
    {
        $container = new Container();
        $fired = false;

        $container->afterResolving(
            RepositoryInterface::class,
            static function () use (&$fired): void {
                $fired = true;
            },
        );
        $container->get(ClassWithoutDependencies::class);

        self::assertFalse($fired);
    }

    public function test_a_non_class_id_still_matches_exactly(): void
    {
        $container = new Container();
        $seen = [];

        $container->set('one', new ClassWithoutDependencies());
        $container->set('two', new ClassWithoutDependencies());

        $container->afterResolving('one', static function () use (&$seen): void {
            $seen[] = 'one';
        });

        $container->get('one');
        $container->get('two');

        self::assertSame(['one'], $seen);
    }

    public function test_hooks_fire_in_registration_order_across_both_kinds(): void
    {
        $container = new Container();
        $order = [];

        $container->afterResolving(RepositoryInterface::class, static function () use (&$order): void {
            $order[] = 'by-type';
        });
        $container->afterResolving(DatabaseRepository::class, static function () use (&$order): void {
            $order[] = 'by-class';
        });

        $container->get(DatabaseRepository::class);

        self::assertSame(['by-type', 'by-class'], $order);
    }

    public function test_hooks_fire_for_get_or_fail(): void
    {
        $container = new Container();
        $fired = false;

        $container->afterResolving(ClassWithoutDependencies::class, static function () use (&$fired): void {
            $fired = true;
        });
        $container->getOrFail(ClassWithoutDependencies::class);

        self::assertTrue($fired);
    }

    /**
     * make() with overridden arguments took its own path and skipped the hooks
     * entirely.
     */
    public function test_hooks_fire_for_make_with_overridden_arguments(): void
    {
        $container = new Container();
        $seen = null;

        $container->afterResolving(
            PersonWithoutDefaultValues::class,
            static function (object $instance) use (&$seen): void {
                $seen = $instance;
            },
        );

        $person = $container->make(PersonWithoutDefaultValues::class, ['name' => 'Frodo']);

        self::assertSame($person, $seen);
    }

    public function test_hooks_fire_for_make_without_arguments(): void
    {
        $container = new Container();
        $fired = false;

        $container->afterResolving(ClassWithoutDependencies::class, static function () use (&$fired): void {
            $fired = true;
        });
        $container->make(ClassWithoutDependencies::class);

        self::assertTrue($fired);
    }

    public function test_a_hook_receives_the_instance_and_the_container(): void
    {
        $container = new Container();
        $seenContainer = null;

        $container->afterResolving(
            ClassWithoutDependencies::class,
            static function (object $instance, ContainerInterface $c) use (&$seenContainer): void {
                $seenContainer = $c;
            },
        );
        $container->get(ClassWithoutDependencies::class);

        self::assertSame($container, $seenContainer);
    }

    /**
     * Wiring that failed halfway leaves an object the application believes is
     * configured. Serving it again would be worse than the exception.
     */
    public function test_a_throwing_hook_evicts_the_instance(): void
    {
        $container = new Container();
        $container->set('svc', new ClassWithoutDependencies());

        $container->afterResolving('svc', static function (): void {
            throw new RuntimeException('wiring failed');
        });

        try {
            $container->get('svc');
            self::fail('the hook should have thrown');
        } catch (RuntimeException) {
            // expected
        }

        self::assertFalse($container->has('svc'));
    }

    public function test_a_throwing_hook_still_propagates(): void
    {
        $container = new Container();
        $container->set('svc', new ClassWithoutDependencies());
        $container->afterResolving('svc', static function (): void {
            throw new RuntimeException('wiring failed');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('wiring failed');

        $container->get('svc');
    }

    public function test_a_container_with_no_hooks_is_unaffected(): void
    {
        $container = new Container();

        self::assertInstanceOf(
            ClassWithoutDependencies::class,
            $container->get(ClassWithoutDependencies::class),
        );
    }

    public function test_an_alias_is_resolved_before_registering(): void
    {
        $container = new Container();
        $container->alias('repo', DatabaseRepository::class);

        $fired = false;
        $container->afterResolving('repo', static function () use (&$fired): void {
            $fired = true;
        });

        $container->get(DatabaseRepository::class);

        self::assertTrue($fired);
    }
}
