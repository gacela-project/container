<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\Exception\DependencyNotFoundException;
use GacelaTest\Fake\ClassWithoutDependencies;
use PHPUnit\Framework\TestCase;

final class MakeAndGetOrFailTest extends TestCase
{
    public function test_make_resolves_a_typed_instance(): void
    {
        $container = new Container();

        $actual = $container->make(ClassWithoutDependencies::class);

        self::assertEquals(new ClassWithoutDependencies(), $actual);
    }

    public function test_get_or_fail_returns_the_resolved_instance(): void
    {
        $container = new Container();

        $actual = $container->getOrFail(ClassWithoutDependencies::class);

        self::assertEquals(new ClassWithoutDependencies(), $actual);
    }

    public function test_get_or_fail_throws_when_id_is_not_resolvable(): void
    {
        $container = new Container();

        $this->expectException(DependencyNotFoundException::class);
        $this->expectExceptionMessage('unknown-service');

        $container->getOrFail('unknown-service');
    }

    public function test_make_throws_when_class_is_not_resolvable(): void
    {
        $container = new Container();

        $this->expectException(DependencyNotFoundException::class);

        /** @phpstan-ignore argument.type */
        $container->make('unknown-service');
    }
}
