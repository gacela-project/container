<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\ContainerInterface;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\ForwardingContainer;
use GacelaTest\Fake\PersonWithoutDefaultValues;
use PHPUnit\Framework\TestCase;
use WeakReference;

/**
 * What a container designed to be wrapped owes its wrapper.
 *
 * `Container` is final, so a decorator composes — and then every user closure
 * receives the inner container rather than the decorator, which is the problem
 * withSelfReference() exists to remove.
 */
final class DecoratorSeamsTest extends TestCase
{
    protected function setUp(): void
    {
        Container::resetStaticCaches();
    }

    protected function tearDown(): void
    {
        Container::resetStaticCaches();
    }

    public function test_without_a_facade_a_closure_still_receives_the_container(): void
    {
        $container = new Container();
        $seen = null;

        $container->set('svc', static function (ContainerInterface $c) use (&$seen): object {
            $seen = $c;

            return new ClassWithoutDependencies();
        });
        $container->get('svc');

        self::assertSame($container, $seen);
    }

    public function test_a_binding_closure_receives_the_facade(): void
    {
        $container = new Container();
        $facade = new ForwardingContainer($container);
        $container->withSelfReference($facade);

        $seen = null;
        $container->set('svc', static function (ContainerInterface $c) use (&$seen): object {
            $seen = $c;

            return new ClassWithoutDependencies();
        });
        $container->get('svc');

        self::assertSame($facade, $seen);
    }

    public function test_a_contextual_closure_receives_the_facade(): void
    {
        $container = new Container();
        $facade = new ForwardingContainer($container);
        $container->withSelfReference($facade);

        $seen = null;
        $container->when(PersonWithoutDefaultValues::class)
            ->needs('$name')
            ->give(static function (ContainerInterface $c) use (&$seen): string {
                $seen = $c;

                return 'Frodo';
            });

        self::assertSame('Frodo', $container->get(PersonWithoutDefaultValues::class)->name);
        self::assertSame($facade, $seen);
    }

    public function test_an_after_resolving_hook_receives_the_facade(): void
    {
        $container = new Container();
        $facade = new ForwardingContainer($container);
        $container->withSelfReference($facade);

        $seen = null;
        $container->afterResolving(
            ClassWithoutDependencies::class,
            static function (object $instance, ContainerInterface $c) use (&$seen): void {
                $seen = $c;
            },
        );
        $container->get(ClassWithoutDependencies::class);

        self::assertSame($facade, $seen);
    }

    /**
     * The reason a decorator could not simply re-wrap closures itself:
     * factory() marks them by identity, so a wrapper silently drops the mark.
     * Nothing is re-wrapped here, so the mark survives.
     */
    public function test_a_factory_marked_closure_keeps_its_mark(): void
    {
        $container = new Container();
        $container->withSelfReference(new ForwardingContainer($container));

        $container->set('svc', $container->factory(
            static fn (ContainerInterface $c): object => new ClassWithoutDependencies(),
        ));

        self::assertNotSame($container->get('svc'), $container->get('svc'));
    }

    public function test_a_protected_closure_is_still_returned_as_itself(): void
    {
        $container = new Container();
        $container->withSelfReference(new ForwardingContainer($container));

        $closure = static fn (): string => 'not a factory';
        $container->set('cfg', $container->protect($closure));

        self::assertSame($closure, $container->get('cfg'));
    }

    public function test_it_is_fluent(): void
    {
        $container = new Container();

        self::assertSame($container, $container->withSelfReference(new ForwardingContainer($container)));
    }

    /**
     * Weak on purpose: a decorator holds its inner container, so a strong
     * pointer back would rebuild the cycle #149 removed.
     */
    public function test_the_facade_is_held_weakly(): void
    {
        $container = new Container();
        $facade = new ForwardingContainer($container);
        $container->withSelfReference($facade);

        $weak = WeakReference::create($facade);
        unset($facade);

        self::assertNull($weak->get());
    }

    public function test_a_dropped_facade_falls_back_to_the_container(): void
    {
        $container = new Container();
        $container->withSelfReference(new ForwardingContainer($container));

        $seen = null;
        $container->afterResolving(
            ClassWithoutDependencies::class,
            static function (object $instance, ContainerInterface $c) use (&$seen): void {
                $seen = $c;
            },
        );
        $container->get(ClassWithoutDependencies::class);

        self::assertSame($container, $seen);
    }

    /**
     * The resolver is built on first use, so registering the facade after
     * something has already resolved has to reach the one that exists — not
     * only the one built later.
     */
    public function test_a_facade_registered_after_the_first_resolution_still_applies(): void
    {
        $container = new Container();
        $container->get(ClassWithoutDependencies::class);

        $facade = new ForwardingContainer($container);
        $container->withSelfReference($facade);

        $seen = null;
        $container->when(PersonWithoutDefaultValues::class)
            ->needs('$name')
            ->give(static function (ContainerInterface $c) use (&$seen): string {
                $seen = $c;

                return 'Sam';
            });
        $container->get(PersonWithoutDefaultValues::class);

        self::assertSame($facade, $seen);
    }

    public function test_a_facade_registered_before_any_resolution_applies(): void
    {
        $container = new Container();
        $facade = new ForwardingContainer($container);
        $container->withSelfReference($facade);

        $seen = null;
        $container->when(PersonWithoutDefaultValues::class)
            ->needs('$name')
            ->give(static function (ContainerInterface $c) use (&$seen): string {
                $seen = $c;

                return 'Merry';
            });
        $container->get(PersonWithoutDefaultValues::class);

        self::assertSame($facade, $seen);
    }

    public function test_load_reports_every_id_it_registered(): void
    {
        $container = new Container();
        $seen = [];

        $container->load([
            ClassWithoutDependencies::class => ClassWithoutDependencies::class,
            'db.dsn' => ['value' => 'pgsql://localhost/app'],
            'repo' => ['alias' => ClassWithoutDependencies::class],
        ], static function (string $id) use (&$seen): void {
            $seen[] = $id;
        });

        self::assertSame([ClassWithoutDependencies::class, 'db.dsn', 'repo'], $seen);
    }

    /**
     * The gap this closes: an alias lives in a third registry, so reading ids
     * back off the container undercounts and a listener cannot tell.
     */
    public function test_the_report_includes_aliases(): void
    {
        $container = new Container();
        $seen = [];

        $container->load(
            ['repo' => ['alias' => ClassWithoutDependencies::class]],
            static function (string $id) use (&$seen): void {
                $seen[] = $id;
            },
        );

        self::assertSame(['repo'], $seen);
    }

    public function test_load_without_a_listener_behaves_as_before(): void
    {
        $container = new Container();
        $container->load([ClassWithoutDependencies::class => ClassWithoutDependencies::class]);

        self::assertTrue($container->has(ClassWithoutDependencies::class));
    }

    public function test_load_file_reports_its_ids_too(): void
    {
        $container = new Container();
        $seen = [];

        $container->loadFile(
            __DIR__ . '/../Fake/definitions/services.json',
            static function (string $id) use (&$seen): void {
                $seen[] = $id;
            },
        );

        self::assertContains('db.dsn', $seen);
        self::assertContains('repository', $seen);
    }
}
