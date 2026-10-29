<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\Person;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WeakReference;

final class AfterResolvingTest extends TestCase
{
    public function test_callbacks_run_in_registration_order_with_the_instance(): void
    {
        $container = new Container();
        $log = [];

        $container->afterResolving(Person::class, static function (Person $p) use (&$log): void {
            $log[] = 'a';
        });
        $container->afterResolving(Person::class, static function (Person $p) use (&$log): void {
            $log[] = 'b';
        });

        $container->get(Person::class);

        self::assertSame(['a', 'b'], $log);
    }

    public function test_callback_receives_the_instance_and_the_container(): void
    {
        $container = new Container();
        $received = null;
        $containerArg = null;

        $container->afterResolving(Person::class, static function (Person $p, Container $c) use (&$received, &$containerArg): void {
            $received = $p;
            $containerArg = $c;
        });

        $person = $container->get(Person::class);

        self::assertSame($person, $received);
        self::assertSame($container, $containerArg);
    }

    public function test_callback_does_not_fire_for_unrelated_ids(): void
    {
        $container = new Container();
        $fired = false;

        $container->afterResolving(Person::class, static function () use (&$fired): void {
            $fired = true;
        });

        $container->get(ClassWithoutDependencies::class);

        self::assertFalse($fired);
    }

    /**
     * @param callable(Container): void $register
     */
    #[DataProvider('sharedRegistrations')]
    public function test_a_hook_runs_once_for_a_shared_instance(callable $register): void
    {
        $container = new Container();
        $register($container);
        $calls = 0;
        $container->afterResolving(ClassWithoutDependencies::class, static function () use (&$calls): void {
            ++$calls;
        });

        $instance = $container->get(ClassWithoutDependencies::class);

        self::assertSame($instance, $container->getOrFail(ClassWithoutDependencies::class));
        self::assertSame($instance, $container->make(ClassWithoutDependencies::class));
        self::assertSame(1, $calls);
    }

    /**
     * @return iterable<string, array{callable(Container): void}>
     */
    public static function sharedRegistrations(): iterable
    {
        yield 'stored object' => [static function (Container $container): void {
            $container->set(ClassWithoutDependencies::class, new ClassWithoutDependencies());
        }];
        yield 'stored closure' => [static function (Container $container): void {
            $container->set(ClassWithoutDependencies::class, static fn (): object => new ClassWithoutDependencies());
        }];
        yield 'class singleton' => [static function (Container $container): void {
            $container->singleton(ClassWithoutDependencies::class);
        }];
        yield 'closure singleton' => [static function (Container $container): void {
            $container->singleton(ClassWithoutDependencies::class, static fn (): object => new ClassWithoutDependencies());
        }];
    }

    public function test_a_hook_runs_for_each_fresh_instance(): void
    {
        $container = new Container();
        $container->set('service', $container->factory(static fn (): object => new ClassWithoutDependencies()));
        $calls = 0;
        $container->afterResolving('service', static function () use (&$calls): void {
            ++$calls;
        });

        $first = $container->get('service');
        $second = $container->get('service');

        self::assertNotSame($first, $second);
        self::assertSame(2, $calls);
    }

    public function test_type_and_id_hooks_track_the_same_object_across_ids_and_aliases(): void
    {
        $container = new Container();
        $instance = new ClassWithoutDependencies();
        $container->set('one', $instance);
        $container->set('two', $instance);
        $container->alias('alias', 'one');
        $log = [];
        $container->afterResolving(ClassWithoutDependencies::class, static function () use (&$log): void {
            $log[] = 'type';
        });
        $container->afterResolving('one', static function () use (&$log): void {
            $log[] = 'one';
        });
        $container->afterResolving('two', static function () use (&$log): void {
            $log[] = 'two';
        });

        $container->get('one');
        $container->get('two');
        $container->get('alias');
        $container->get('two');

        self::assertSame(['type', 'one', 'two'], $log);
    }

    public function test_a_hook_registered_later_runs_without_repeating_earlier_hooks(): void
    {
        $container = new Container();
        $container->set('service', new ClassWithoutDependencies());
        $log = [];
        $container->afterResolving('service', static function () use (&$log): void {
            $log[] = 'first';
        });
        $container->get('service');
        $container->afterResolving('service', static function () use (&$log): void {
            $log[] = 'second';
        });
        $container->get('service');
        $container->get('service');

        self::assertSame(['first', 'second'], $log);
    }

    public function test_replacing_an_instance_runs_its_hooks_again(): void
    {
        $container = new Container();
        $container->set('service', new ClassWithoutDependencies());
        $calls = 0;
        $container->afterResolving('service', static function () use (&$calls): void {
            ++$calls;
        });
        $first = $container->get('service');
        $container->remove('service');
        $container->set('service', new ClassWithoutDependencies());
        $second = $container->get('service');
        $container->get('service');

        self::assertNotSame($first, $second);
        self::assertSame(2, $calls);
    }

    public function test_a_forgotten_service_runs_hooks_on_the_rebuilt_instance(): void
    {
        $container = new Container();
        $container->set('service', static fn (): object => new ClassWithoutDependencies());
        $calls = 0;
        $container->afterResolving('service', static function () use (&$calls): void {
            ++$calls;
        });
        $first = $container->get('service');
        $container->forget('service');
        $second = $container->get('service');
        $container->get('service');

        self::assertNotSame($first, $second);
        self::assertSame(2, $calls);
    }

    public function test_hook_tracking_does_not_retain_transient_instances(): void
    {
        $container = new Container();
        $container->afterResolving(ClassWithoutDependencies::class, static function (): void {});
        $instance = $container->get(ClassWithoutDependencies::class);
        $reference = WeakReference::create($instance);
        unset($instance);

        self::assertNull($reference->get());
    }

    public function test_parent_and_scope_hooks_each_run_once_for_a_shared_instance(): void
    {
        $parent = new Container();
        $parent->singleton(ClassWithoutDependencies::class);
        $calls = [];
        $parent->afterResolving(ClassWithoutDependencies::class, static function () use (&$calls): void {
            $calls[] = 'parent';
        });
        $one = $parent->createScope();
        $two = $parent->createScope();
        $one->afterResolving(ClassWithoutDependencies::class, static function () use (&$calls): void {
            $calls[] = 'one';
        });
        $two->afterResolving(ClassWithoutDependencies::class, static function () use (&$calls): void {
            $calls[] = 'two';
        });

        $instance = $parent->get(ClassWithoutDependencies::class);
        self::assertSame($instance, $one->get(ClassWithoutDependencies::class));
        self::assertSame($instance, $two->get(ClassWithoutDependencies::class));
        $one->get(ClassWithoutDependencies::class);
        $two->get(ClassWithoutDependencies::class);

        self::assertSame(['parent', 'one', 'two'], $calls);
    }

    public function test_a_hook_resolving_its_own_instance_is_not_repeated(): void
    {
        $container = new Container();
        $container->set('service', new ClassWithoutDependencies());
        $calls = 0;
        $container->afterResolving('service', static function (object $instance, Container $container) use (&$calls): void {
            if (++$calls === 1) {
                self::assertSame($instance, $container->get('service'));
            }
        });
        $container->get('service');

        self::assertSame(1, $calls);
    }

    public function test_a_failed_hook_can_retry_when_a_factory_returns_the_same_object(): void
    {
        $container = new Container();
        $instance = new ClassWithoutDependencies();
        $container->set('service', $container->factory(static fn (): object => $instance));
        $calls = 0;
        $container->afterResolving('service', static function () use (&$calls): void {
            if (++$calls === 1) {
                throw new RuntimeException('wiring failed');
            }
        });

        try {
            $container->get('service');
            self::fail('the hook should have thrown');
        } catch (RuntimeException) {
        }
        self::assertSame($instance, $container->get('service'));
        $container->get('service');

        self::assertSame(2, $calls);
    }

    public function test_non_object_values_keep_running_hooks_on_each_resolution(): void
    {
        $container = new Container();
        $container->set('value', 'config');
        $calls = 0;
        $container->afterResolving('value', static function () use (&$calls): void {
            ++$calls;
        });
        $container->get('value');
        $container->get('value');

        self::assertSame(2, $calls);
    }
}
