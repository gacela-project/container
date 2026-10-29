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

    public function test_an_extended_factory_stays_a_factory(): void
    {
        $container = new Container();
        $container->set('svc', $container->factory(
            static fn (ContainerInterface $c): object => new ClassWithoutDependencies(),
        ));

        $container->extend('svc', static fn (object $instance): object => $instance);

        self::assertNotSame($container->get('svc'), $container->get('svc'));
    }

    public function test_extending_one_factory_id_preserves_other_ids_using_the_original_closure(): void
    {
        $container = new Container();
        $factory = $container->factory(static fn (): object => new ClassWithoutDependencies());
        $container->set('extended', $factory);
        $container->set('original', $factory);
        $extensions = 0;
        $container->extend('extended', static function (object $instance) use (&$extensions): object {
            ++$extensions;

            return $instance;
        });
        $container->set('reused', $factory);

        self::assertTrue($container->isFactory('extended'));
        self::assertTrue($container->isFactory('original'));
        self::assertTrue($container->isFactory('reused'));
        self::assertNotSame($container->get('extended'), $container->get('extended'));
        self::assertNotSame($container->get('original'), $container->get('original'));
        self::assertNotSame($container->get('reused'), $container->get('reused'));
        self::assertSame(2, $extensions);
    }

    public function test_extending_a_scope_factory_preserves_other_ids_and_descendant_marks(): void
    {
        $parent = new Container();
        $parent->set('label', 'parent');
        $scope = $parent->createScope();
        $scope->set('label', 'scope');
        $factory = $scope->factory(static fn (ContainerInterface $current): object => (object) ['label' => $current->get('label')]);
        $parent->set('service', $factory);
        $scope->set('extended', $factory);
        $scope->set('original', $factory);
        $descendant = $scope->createScope();
        $descendant->set('label', 'descendant');
        $descendant->set('service', $factory);
        $scope->extend('extended', static function (object $instance, ContainerInterface $current): object {
            $instance->extensionLabel = $current->get('label');

            return $instance;
        });

        self::assertTrue($scope->isFactory('original'));
        self::assertTrue($descendant->isFactory('service'));
        self::assertFalse($parent->isFactory('service'));
        $extended = $scope->get('extended');
        self::assertSame('scope', $extended->label);
        self::assertSame('scope', $extended->extensionLabel);
        self::assertNotSame($extended, $scope->get('extended'));
        self::assertNotSame($scope->get('original'), $scope->get('original'));
        $nested = $descendant->get('service');
        self::assertSame('descendant', $nested->label);
        self::assertNotSame($nested, $descendant->get('service'));
        self::assertObjectNotHasProperty('extensionLabel', $nested);
        $inParent = $parent->get('service');
        self::assertSame('parent', $inParent->label);
        self::assertSame($inParent, $parent->get('service'));
    }

    public function test_shared_factory_marks_do_not_retain_removed_wrappers_or_original_closures(): void
    {
        $container = new Container();
        $payload = new ClassWithoutDependencies();
        $factory = $container->factory(static fn (): object => $payload);
        $container->set('extended', $factory);
        $container->set('original', $factory);
        $wrapper = $container->extend('extended', static fn (object $instance): object => $instance);
        $originalReference = WeakReference::create($factory);
        $wrapperReference = WeakReference::create($wrapper);
        $payloadReference = WeakReference::create($payload);
        unset($factory, $wrapper, $payload);
        $container->remove('extended');

        self::assertNull($wrapperReference->get());
        self::assertNotNull($originalReference->get());
        $container->remove('original');

        self::assertNull($originalReference->get());
        self::assertNull($payloadReference->get());
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

    public function test_removing_a_scope_factory_releases_its_ancestor_marked_closure_and_capture(): void
    {
        $parent = new Container();
        $scope = $parent->createScope();
        $payload = new ClassWithoutDependencies();
        $closure = $parent->factory(static fn (): object => $payload);
        $scope->set('service', $closure);
        $closureReference = WeakReference::create($closure);
        $payloadReference = WeakReference::create($payload);
        unset($closure, $payload);
        $scope->remove('service');

        self::assertNull($closureReference->get());
        self::assertNull($payloadReference->get());
    }

    public function test_removing_a_scope_protected_value_releases_its_ancestor_marked_closure_and_capture(): void
    {
        $parent = new Container();
        $scope = $parent->createScope();
        $payload = new ClassWithoutDependencies();
        $closure = $parent->protect(static fn (): object => $payload);
        $scope->set('service', $closure);
        $closureReference = WeakReference::create($closure);
        $payloadReference = WeakReference::create($payload);
        unset($closure, $payload);
        $scope->remove('service');

        self::assertNull($closureReference->get());
        self::assertNull($payloadReference->get());
    }
}
