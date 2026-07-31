<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Closure;
use Gacela\Container\Container;
use Gacela\Container\ContainerInterface;
use GacelaTest\Fake\ClassWithoutDependencies;
use PHPUnit\Framework\TestCase;
use WeakReference;

/**
 * A factory/protected mark must not outlive the closure it marks.
 *
 * The marks were held strongly and nothing ever removed one, so a container
 * retained every closure it was ever handed — and everything each closed over —
 * whether the binding still existed or ever existed at all.
 *
 * Asserted on WeakReference::get() rather than memory_get_usage(), which is the
 * only thing that distinguishes "released" from "unreachable but not yet
 * collected".
 */
final class ClosureMarkLifetimeTest extends TestCase
{
    protected function setUp(): void
    {
        Container::resetStaticCaches();
        gc_disable();
    }

    protected function tearDown(): void
    {
        gc_enable();
        Container::resetStaticCaches();
    }

    /**
     * factory() marks before anyone decides to register, so a closure that is
     * marked and then dropped is the plainest case.
     */
    public function test_an_unregistered_factory_closure_is_released(): void
    {
        $container = new Container();

        $closure = static fn (ContainerInterface $c): object => new ClassWithoutDependencies();
        $container->factory($closure);

        $weak = WeakReference::create($closure);
        unset($closure);

        self::assertNull($weak->get());
    }

    public function test_an_unregistered_protected_closure_is_released(): void
    {
        $container = new Container();

        $closure = static fn (): string => 'config';
        $container->protect($closure);

        $weak = WeakReference::create($closure);
        unset($closure);

        self::assertNull($weak->get());
    }

    /**
     * The part that bites: the mark keeps the closure alive, and the closure
     * keeps whatever it captured alive with it.
     */
    public function test_what_a_dropped_factory_closure_captured_is_released(): void
    {
        $container = new Container();

        $payload = new ClassWithoutDependencies();
        $closure = static fn (ContainerInterface $c): object => $payload;
        $container->factory($closure);

        $weak = WeakReference::create($payload);
        unset($payload, $closure);

        self::assertNull($weak->get());
    }

    public function test_overwriting_a_factory_binding_releases_the_old_closure(): void
    {
        $container = new Container();

        $first = static fn (ContainerInterface $c): object => new ClassWithoutDependencies();
        $container->set('svc', $container->factory($first));

        $weak = WeakReference::create($first);
        unset($first);

        $container->set('svc', $container->factory(
            static fn (ContainerInterface $c): object => new ClassWithoutDependencies(),
        ));

        self::assertNull($weak->get());
    }

    public function test_removing_a_protected_binding_releases_the_closure(): void
    {
        $container = new Container();

        $closure = static fn (): string => 'config';
        $container->set('cfg', $container->protect($closure));

        $weak = WeakReference::create($closure);
        unset($closure);

        $container->remove('cfg');

        self::assertNull($weak->get());
    }

    /**
     * The mark has to survive exactly as long as the closure does — a weak
     * mark that expired early would silently turn a factory into a singleton.
     */
    public function test_a_registered_factory_closure_keeps_its_mark(): void
    {
        $container = new Container();
        $container->set('svc', $container->factory(
            static fn (ContainerInterface $c): object => new ClassWithoutDependencies(),
        ));

        self::assertNotSame($container->get('svc'), $container->get('svc'));
    }

    public function test_a_registered_protected_closure_keeps_its_mark(): void
    {
        $container = new Container();
        $closure = static fn (): string => 'config';
        $container->set('cfg', $container->protect($closure));

        self::assertSame($closure, $container->get('cfg'));
    }

    /**
     * extend() moves the mark from the original closure to the wrapper, and the
     * wrapper is what the container now holds.
     */
    public function test_an_extended_factory_stays_a_factory(): void
    {
        $container = new Container();
        $container->set('svc', $container->factory(
            static fn (ContainerInterface $c): object => new ClassWithoutDependencies(),
        ));

        $container->extend('svc', static fn (object $instance): object => $instance);

        self::assertNotSame($container->get('svc'), $container->get('svc'));
    }

    public function test_many_unregistered_marks_retain_nothing(): void
    {
        $container = new Container();
        $weak = [];

        for ($i = 0; $i < 200; ++$i) {
            $closure = static fn (ContainerInterface $c): object => new ClassWithoutDependencies();
            $container->factory($closure);
            $weak[] = WeakReference::create($closure);
            unset($closure);
        }

        $alive = 0;
        foreach ($weak as $reference) {
            if ($reference->get() !== null) {
                ++$alive;
            }
        }

        self::assertSame(0, $alive);
    }

    public function test_a_closure_the_caller_still_holds_stays_marked(): void
    {
        $container = new Container();

        $closure = static fn (ContainerInterface $c): object => new ClassWithoutDependencies();
        $marked = $container->factory($closure);

        self::assertInstanceOf(Closure::class, $marked);
        $container->set('svc', $marked);

        self::assertNotSame($container->get('svc'), $container->get('svc'));
    }
}
