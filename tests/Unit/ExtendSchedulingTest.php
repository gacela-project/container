<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use ArrayObject;
use Closure;
use Gacela\Container\Container;
use Gacela\Container\Exception\ContainerException;
use Gacela\Container\FactoryManager;
use GacelaTest\Fake\ClassWithoutDependencies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

final class ExtendSchedulingTest extends TestCase
{
    public function test_extending_an_autowirable_class_schedules_instead_of_throwing(): void
    {
        $container = new Container();

        $result = $container->extend(
            ClassWithoutDependencies::class,
            static fn (object $service): object => $service,
        );

        self::assertInstanceOf(Closure::class, $result);
    }

    public function test_a_scheduled_extension_is_applied_when_the_service_is_set(): void
    {
        $container = new Container();

        $container->extend('service', static fn (object $service): object => new ArrayObject(['extended']));
        $container->set('service', new ClassWithoutDependencies());

        self::assertInstanceOf(ArrayObject::class, $container->get('service'));
    }

    public function test_a_scheduled_extension_on_a_class_id_is_applied_when_set(): void
    {
        $container = new Container();

        $container->extend(
            ClassWithoutDependencies::class,
            static fn (object $service): object => new ArrayObject(['extended']),
        );
        $container->set(ClassWithoutDependencies::class, new ClassWithoutDependencies());

        self::assertInstanceOf(ArrayObject::class, $container->get(ClassWithoutDependencies::class));
    }

    public function test_extending_an_unknown_string_id_still_schedules(): void
    {
        $container = new Container();

        $result = $container->extend('not-a-class', static fn (object $s): object => $s);

        self::assertInstanceOf(Closure::class, $result);
    }

    public function test_extending_an_already_registered_instance_applies_immediately(): void
    {
        $container = new Container();
        $container->set('service', new ClassWithoutDependencies());

        $container->extend('service', static fn (object $s): object => new ArrayObject(['now']));

        self::assertInstanceOf(ArrayObject::class, $container->get('service'));
    }

    public function test_is_factory_is_false_for_an_autowirable_class(): void
    {
        $container = new Container();

        self::assertFalse($container->isFactory(ClassWithoutDependencies::class));
    }

    public function test_array_access_isset_keeps_the_widened_has_semantics(): void
    {
        $container = new Container();

        // offsetExists() intentionally uses the public has(): resolvability.
        self::assertTrue(isset($container[ClassWithoutDependencies::class]));
    }

    /**
     * @param callable(Container): void $register
     */
    #[DataProvider('bindingRegistrations')]
    public function test_extending_a_binding_throws(callable $register): void
    {
        $container = new Container();
        $register($container);

        $this->expectException(ContainerException::class);
        $container->extend('service', static fn (object $service): object => $service);
    }

    /**
     * @param callable(Container): void $register
     */
    #[DataProvider('bindingRegistrations')]
    public function test_extending_a_resolved_binding_throws(callable $register): void
    {
        $container = new Container();
        $register($container);
        $container->get('service');

        $this->expectException(ContainerException::class);
        $container->extend('service', static fn (object $service): object => $service);
    }

    /**
     * @return iterable<string, array{callable(Container): void}>
     */
    public static function bindingRegistrations(): iterable
    {
        yield 'class binding' => [static function (Container $container): void {
            $container->bind('service', ClassWithoutDependencies::class);
        }];
        yield 'closure binding' => [static function (Container $container): void {
            $container->bind('service', static fn (): object => new ClassWithoutDependencies());
        }];
        yield 'object binding' => [static function (Container $container): void {
            $container->bind('service', new ClassWithoutDependencies());
        }];
        yield 'class singleton' => [static function (Container $container): void {
            $container->singleton('service', ClassWithoutDependencies::class);
        }];
        yield 'closure singleton' => [static function (Container $container): void {
            $container->singleton('service', static fn (): object => new ClassWithoutDependencies());
        }];
        yield 'object singleton' => [static function (Container $container): void {
            $container->singleton('service', new ClassWithoutDependencies());
        }];
    }

    public function test_extending_an_alias_to_a_binding_throws(): void
    {
        $container = new Container();
        $container->bind('service', ClassWithoutDependencies::class);
        $container->alias('alias', 'service');

        $this->expectException(ContainerException::class);
        $container->extend('alias', static fn (object $service): object => $service);
    }

    public function test_extending_a_local_binding_in_a_scope_throws(): void
    {
        $container = new Container();
        $scope = $container->createScope();
        $scope->bind('service', ClassWithoutDependencies::class);

        $this->expectException(ContainerException::class);
        $scope->extend('service', static fn (object $service): object => $service);
    }

    public function test_extending_a_stored_instance_that_shadows_a_binding_still_applies(): void
    {
        $container = new Container();
        $container->bind('service', ClassWithoutDependencies::class);
        $container->set('service', new ClassWithoutDependencies());
        $container->extend('service', static fn (object $service): object => new ArrayObject(['extended']));

        self::assertEquals(new ArrayObject(['extended']), $container->get('service'));
    }
    public function test_failed_scheduled_wrapping_restores_an_absent_registration(): void
    {
        $container = new Container();
        $container->extend('service', static fn (object $service): object => $service);

        try {
            $container->set('service', $container->protect(static fn (): string => 'protected'));
            self::fail('protected values cannot be extended');
        } catch (ContainerException) {
        }

        self::assertFalse($container->has('service'));
        self::assertFalse($container->bound('service'));
        self::assertFalse($container->isFrozen('service'));
        self::assertNotContains('service', $container->getRegisteredServices());
    }

    public function test_failed_scheduled_wrapping_discards_old_callbacks_and_allows_new_extensions(): void
    {
        $container = new Container();
        $container->extend('service', static function (): never {
            throw new RuntimeException('stale extension was applied');
        });

        try {
            $container->set('service', $container->protect(static fn (): string => 'protected'));
            self::fail('protected values cannot be extended');
        } catch (ContainerException) {
        }

        $container->remove('service');
        $container->extend('service', static fn (object $service): ArrayObject => new ArrayObject(['fresh']));
        $container->set('service', new ClassWithoutDependencies());

        self::assertEquals(new ArrayObject(['fresh']), $container->get('service'));
    }

    #[DataProvider('previousRegistrations')]
    public function test_failed_scheduled_wrapping_restores_the_previous_local_registration(mixed $previous, mixed $expected): void
    {
        $container = new Container();
        $container->set('service', $previous);
        $property = new ReflectionProperty(Container::class, 'factoryManager');
        $manager = $property->getValue($container);
        self::assertInstanceOf(FactoryManager::class, $manager);
        $manager->scheduleExtension('service', static fn (object $service): object => $service);

        try {
            $container->set('service', $container->protect(static fn (): string => 'protected'));
            self::fail('protected values cannot be extended');
        } catch (ContainerException) {
        }

        self::assertTrue($container->bound('service'));
        self::assertFalse($container->isFrozen('service'));
        self::assertSame($expected, $container->get('service'));
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function previousRegistrations(): iterable
    {
        yield 'stored null' => [null, null];
        $instance = new ClassWithoutDependencies();
        yield 'stored instance' => [$instance, $instance];
        yield 'shared definition' => [static fn (): object => $instance, $instance];
    }

    public function test_failed_scope_wrapping_restores_the_parent_service(): void
    {
        $parent = new Container();
        $scope = $parent->createScope();
        $scope->extend('service', static fn (object $service): object => $service);
        $instance = new ClassWithoutDependencies();
        $parent->set('service', $instance);

        try {
            $scope->set('service', $scope->protect(static fn (): string => 'protected'));
            self::fail('protected values cannot be extended');
        } catch (ContainerException) {
        }

        self::assertSame($instance, $scope->get('service'));
        self::assertSame($instance, $parent->get('service'));
    }

    public function test_failed_wrapping_uses_the_canonical_alias_id_for_rollback_and_retry(): void
    {
        $container = new Container();
        $container->alias('alias', 'service');
        $container->extend('alias', static fn (object $service): object => $service);

        try {
            $container->set('alias', $container->protect(static fn (): string => 'protected'));
            self::fail('protected values cannot be extended');
        } catch (ContainerException) {
        }

        self::assertFalse($container->bound('service'));
        $container->extend('service', static fn (object $service): ArrayObject => new ArrayObject(['fresh']));
        $container->set('alias', new ClassWithoutDependencies());

        self::assertEquals(new ArrayObject(['fresh']), $container->get('alias'));
    }

    public function test_failed_wrapping_does_not_evict_an_existing_class_singleton(): void
    {
        $container = new Container();
        $container->extend(ClassWithoutDependencies::class, static fn (object $service): object => $service);
        $container->singleton(ClassWithoutDependencies::class);
        $singleton = $container->get(ClassWithoutDependencies::class);

        try {
            $container->set(ClassWithoutDependencies::class, $container->protect(static fn (): string => 'protected'));
            self::fail('protected values cannot be extended');
        } catch (ContainerException) {
        }

        self::assertSame($singleton, $container->get(ClassWithoutDependencies::class));
    }

    public function test_nested_pending_application_preserves_outer_state_and_the_original_exception(): void
    {
        $manager = new FactoryManager();
        $manager->scheduleExtension('outer', static fn (): null => null);
        $manager->scheduleExtension('inner', static fn (): null => null);
        $failure = new RuntimeException('wrapping failed');
        $manager->applyPendingExtensions('outer', static function (Closure $extension) use ($manager, $failure): void {
            try {
                $manager->applyPendingExtensions('inner', static function (Closure $extension) use ($manager, $failure): never {
                    self::assertTrue($manager->isCurrentlyExtending('outer'));
                    self::assertTrue($manager->isCurrentlyExtending('inner'));

                    throw $failure;
                });
                self::fail('the original failure should escape the inner application');
            } catch (RuntimeException $exception) {
                self::assertSame($failure, $exception);
            }

            self::assertTrue($manager->isCurrentlyExtending('outer'));
            self::assertFalse($manager->isCurrentlyExtending('inner'));
        });

        self::assertFalse($manager->isCurrentlyExtending('outer'));
        self::assertFalse($manager->hasPendingExtensions('outer'));
        self::assertFalse($manager->hasPendingExtensions('inner'));
    }

}
