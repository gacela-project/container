<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\PersonInterface;
use GacelaTest\Fake\RepositoryInterface;
use PHPUnit\Framework\TestCase;

final class ConditionalRegistrationTest extends TestCase
{
    public function test_bound_is_true_for_constructor_bindings(): void
    {
        $container = new Container([RepositoryInterface::class => DatabaseRepository::class]);

        self::assertTrue($container->bound(RepositoryInterface::class));
    }

    public function test_bound_is_true_after_runtime_bind_and_set(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, DatabaseRepository::class);
        $container->set('service', new ClassWithoutDependencies());

        self::assertTrue($container->bound(RepositoryInterface::class));
        self::assertTrue($container->bound('service'));
    }

    public function test_bound_resolves_aliases(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, DatabaseRepository::class);
        $container->alias('repo', RepositoryInterface::class);

        self::assertTrue($container->bound('repo'));
    }

    public function test_bound_is_false_for_unknown_id(): void
    {
        $container = new Container();

        self::assertFalse($container->bound('nothing'));
    }

    public function test_bind_if_keeps_the_first_binding(): void
    {
        $container = new Container();
        $container->bindIf(PersonInterface::class, static fn (): Person => new Person('first'));
        $container->bindIf(PersonInterface::class, static fn (): Person => new Person('second'));

        self::assertSame('first', $container->get(PersonInterface::class)->name);
    }

    public function test_singleton_if_keeps_the_first_binding_and_reuses_instance(): void
    {
        $container = new Container();
        $container->singletonIf(PersonInterface::class, static fn (): Person => new Person('single'));
        $container->singletonIf(PersonInterface::class, static fn (): Person => new Person('other'));

        $first = $container->get(PersonInterface::class);
        $second = $container->get(PersonInterface::class);

        self::assertSame($first, $second);
        self::assertSame('single', $first->name);
    }
}
