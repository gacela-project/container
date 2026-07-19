<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use GacelaTest\Fake\ClassWithObjectDependencies;
use PHPUnit\Framework\TestCase;

final class TransientChildResolutionTest extends TestCase
{
    public function test_transient_parents_do_not_share_child_instances(): void
    {
        $container = new Container();

        $first = $container->get(ClassWithObjectDependencies::class);
        $second = $container->get(ClassWithObjectDependencies::class);

        self::assertNotSame($first, $second);
        self::assertNotSame(
            $first->person,
            $second->person,
            'Transient services must not reuse cached child instances',
        );
    }

    public function test_transient_children_stay_fresh_after_warmup(): void
    {
        $container = new Container();
        $container->warmUp([ClassWithObjectDependencies::class]);

        $first = $container->get(ClassWithObjectDependencies::class);
        $second = $container->get(ClassWithObjectDependencies::class);

        self::assertNotSame($first->person, $second->person);
    }
}
