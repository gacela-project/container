<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\Person;
use PHPUnit\Framework\TestCase;

/**
 * Whether a stored instance declares __invoke is a fact about its class.
 *
 * InstanceRegistry::get() asked method_exists() on every read to answer it,
 * which is a question the class settles once. The answer is memoized per class
 * now, so the behaviour it drives has to be pinned: a stored closure is still
 * invoked and replaced by its result, a factory closure still re-runs, a
 * protected closure is still handed back untouched, and a plain object is still
 * returned as it was.
 */
final class InvokableInstanceMemoTest extends TestCase
{
    protected function setUp(): void
    {
        Container::resetStaticCaches();
    }

    protected function tearDown(): void
    {
        Container::resetStaticCaches();
    }

    public function test_a_stored_closure_is_invoked_once_and_replaced_by_its_result(): void
    {
        $calls = 0;
        $container = new Container();
        $container->set('service', static function () use (&$calls): ClassWithoutDependencies {
            ++$calls;

            return new ClassWithoutDependencies();
        });

        $first = $container->get('service');
        $second = $container->get('service');

        self::assertInstanceOf(ClassWithoutDependencies::class, $first);
        self::assertSame($first, $second);
        self::assertSame(1, $calls);
    }

    public function test_a_plain_object_is_returned_untouched_on_every_read(): void
    {
        $instance = new ClassWithoutDependencies();
        $container = new Container();
        $container->set('service', $instance);

        self::assertSame($instance, $container->get('service'));
        self::assertSame($instance, $container->get('service'));
    }

    public function test_two_containers_agree_about_the_same_invokable_class(): void
    {
        // The memo is shared across containers, so a class first seen through
        // one must behave identically through the next.
        $first = new Container();
        $first->set('service', new InvokableFake());

        $second = new Container();
        $second->set('service', new InvokableFake());

        self::assertInstanceOf(Person::class, $first->get('service'));
        self::assertInstanceOf(Person::class, $second->get('service'));
    }

    public function test_a_non_invokable_class_stays_non_invokable_for_the_next_container(): void
    {
        $instance = new ClassWithoutDependencies();

        $first = new Container();
        $first->set('service', $instance);
        self::assertSame($instance, $first->get('service'));

        $second = new Container();
        $second->set('service', $instance);

        // A cached "no" must not turn into a "yes" for a second container, and
        // the object must not be invoked as though it were a factory.
        self::assertSame($instance, $second->get('service'));
    }

    public function test_the_memo_is_cleared_by_the_static_reset(): void
    {
        $container = new Container();
        $container->set('service', new InvokableFake());

        self::assertInstanceOf(Person::class, $container->get('service'));

        Container::resetStaticCaches();

        // Rebuilt, not lost: the answer after a reset is the same answer.
        $other = new Container();
        $other->set('service', new InvokableFake());

        self::assertInstanceOf(Person::class, $other->get('service'));
    }
}

final class InvokableFake
{
    public function __invoke(): Person
    {
        return new Person();
    }
}
