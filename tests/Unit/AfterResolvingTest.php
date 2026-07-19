<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\Person;
use PHPUnit\Framework\TestCase;

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
}
