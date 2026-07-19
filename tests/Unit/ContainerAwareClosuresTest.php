<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\PersonInterface;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\ServiceWithRepository;
use GacelaTest\Fake\ServiceWithScalarDependency;
use PHPUnit\Framework\TestCase;

final class ContainerAwareClosuresTest extends TestCase
{
    public function test_bound_closure_receives_the_container(): void
    {
        $container = new Container();
        $container->bind(
            PersonInterface::class,
            static fn (Container $c): Person => $c->make(Person::class, ['name' => 'from-container']),
        );

        $person = $container->get(PersonInterface::class);

        self::assertInstanceOf(Person::class, $person);
        self::assertSame('from-container', $person->name);
    }

    public function test_zero_argument_binding_closure_still_works(): void
    {
        $container = new Container();
        $container->bind(PersonInterface::class, static fn (): Person => new Person('legacy'));

        self::assertSame('legacy', $container->get(PersonInterface::class)->name);
    }

    public function test_contextual_give_closure_receives_the_container(): void
    {
        $container = new Container();
        $container->when(ServiceWithRepository::class)
            ->needs(RepositoryInterface::class)
            ->give(static fn (Container $c): RepositoryInterface => $c->get(DatabaseRepository::class));

        $service = $container->get(ServiceWithRepository::class);

        self::assertInstanceOf(DatabaseRepository::class, $service->repository);
    }

    public function test_named_contextual_give_closure_receives_the_container(): void
    {
        $container = new Container();
        $container->when(ServiceWithScalarDependency::class)
            ->needs('$apiKey')
            ->give(static fn (Container $c): string => $c instanceof Container ? 'ok' : 'no');

        self::assertSame('ok', $container->get(ServiceWithScalarDependency::class)->apiKey);
    }

    public function test_singleton_closure_receives_the_container(): void
    {
        $container = new Container();
        $container->singleton(
            PersonInterface::class,
            static fn (Container $c): Person => $c->make(Person::class, ['name' => 'single']),
        );

        $first = $container->get(PersonInterface::class);
        $second = $container->get(PersonInterface::class);

        self::assertSame($first, $second);
        self::assertSame('single', $first->name);
    }
}
