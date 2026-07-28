<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\InMemoryRepository;
use PHPUnit\Framework\TestCase;

/**
 * The generator refuses to emit a `new` for anything bound or carrying a
 * lifetime attribute, so a generated expression is only ever written for a class
 * nothing was registered for. Registration made *after* the file is installed is
 * the case that rule does not cover, and it used to lose silently: a late bind()
 * resolved to the wrong class, and a late singleton() built a fresh instance per
 * get() while the application believed the service was shared.
 */
final class LateRegistrationOutranksGeneratedFactoriesTest extends TestCase
{
    public function test_a_bind_made_after_generated_factories_are_installed_wins(): void
    {
        $container = new Container();
        $container->useCompiledFactories([
            InMemoryRepository::class => static fn (): InMemoryRepository => new InMemoryRepository(),
        ]);

        $container->bind(InMemoryRepository::class, DatabaseRepository::class);

        self::assertInstanceOf(DatabaseRepository::class, $container->get(InMemoryRepository::class));
    }

    public function test_a_bind_made_before_generated_factories_are_installed_wins(): void
    {
        $container = new Container();
        $container->bind(InMemoryRepository::class, DatabaseRepository::class);

        $container->useCompiledFactories([
            InMemoryRepository::class => static fn (): InMemoryRepository => new InMemoryRepository(),
        ]);

        self::assertInstanceOf(DatabaseRepository::class, $container->get(InMemoryRepository::class));
    }

    public function test_a_singleton_made_after_generated_factories_are_installed_is_shared(): void
    {
        $container = new Container();
        $container->useCompiledFactories([
            InMemoryRepository::class => static fn (): InMemoryRepository => new InMemoryRepository(),
        ]);

        $container->singleton(InMemoryRepository::class);

        self::assertSame(
            $container->get(InMemoryRepository::class),
            $container->get(InMemoryRepository::class),
        );
    }

    public function test_a_stored_instance_outranks_a_generated_factory(): void
    {
        $instance = new InMemoryRepository();

        $container = new Container();
        $container->useCompiledFactories([
            InMemoryRepository::class => static fn (): InMemoryRepository => new InMemoryRepository(),
        ]);
        $container->set(InMemoryRepository::class, $instance);

        self::assertSame($instance, $container->get(InMemoryRepository::class));
    }

    public function test_a_class_with_no_registration_still_takes_the_generated_path(): void
    {
        $calls = 0;

        $container = new Container();
        $container->useCompiledFactories([
            InMemoryRepository::class => static function () use (&$calls): InMemoryRepository {
                ++$calls;
                return new InMemoryRepository();
            },
        ]);

        $container->get(InMemoryRepository::class);

        self::assertSame(1, $calls);
    }

    public function test_a_scope_binding_outranks_a_factory_inherited_from_its_parent(): void
    {
        $container = new Container();
        $container->useCompiledFactories([
            InMemoryRepository::class => static fn (): InMemoryRepository => new InMemoryRepository(),
        ]);

        $scope = $container->createScope();
        $scope->bind(InMemoryRepository::class, DatabaseRepository::class);

        self::assertInstanceOf(DatabaseRepository::class, $scope->get(InMemoryRepository::class));
    }
}
