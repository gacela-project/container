<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\Exception\ContainerException;
use Gacela\Container\Exception\DependencyNotFoundException;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\InMemoryRepository;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\ServiceWithNullableDependency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NullRegistrationTest extends TestCase
{
    public function test_a_stored_null_is_registered_and_frozen_when_read(): void
    {
        $container = new Container();
        $container->set('feature.flag', null);

        self::assertTrue($container->has('feature.flag'));
        self::assertTrue($container->bound('feature.flag'));
        self::assertTrue($container->provides('feature.flag'));
        self::assertContains('feature.flag', $container->getRegisteredServices());
        self::assertFalse($container->isFrozen('feature.flag'));
        self::assertNull($container->get('feature.flag'));
        self::assertTrue($container->isFrozen('feature.flag'));
        self::assertNull($container->get('feature.flag'));
    }

    public function test_a_resolved_null_cannot_be_overwritten(): void
    {
        $container = new Container();
        $container->set('feature.flag', null);
        $container->get('feature.flag');
        $this->expectException(ContainerException::class);

        $container->set('feature.flag', true);
    }

    public function test_an_unread_null_can_still_be_replaced(): void
    {
        $container = new Container();
        $container->set('feature.flag', null);
        $container->set('feature.flag', true);

        self::assertTrue($container->get('feature.flag'));
    }

    public function test_a_null_registered_under_a_class_prevents_autowiring(): void
    {
        $container = new Container();
        $container->set(ClassWithoutDependencies::class, null);

        self::assertNull($container->get(ClassWithoutDependencies::class));
        self::assertTrue($container->isFrozen(ClassWithoutDependencies::class));
    }

    public function test_make_does_not_autowire_over_a_stored_null(): void
    {
        $container = new Container();
        $container->set(ClassWithoutDependencies::class, null);
        $this->expectException(DependencyNotFoundException::class);

        $container->make(ClassWithoutDependencies::class);
    }

    public function test_a_nullable_constructor_dependency_reads_and_freezes_the_null_registration(): void
    {
        $container = new Container();
        $container->set(RepositoryInterface::class, null);
        $service = $container->get(ServiceWithNullableDependency::class);

        self::assertNull($service->repository);
        self::assertTrue($container->isFrozen(RepositoryInterface::class));
    }

    public function test_aliases_reach_a_null_registration_and_its_frozen_state(): void
    {
        $container = new Container();
        $container->set('feature.flag', null);
        $container->alias('flag', 'feature.flag');

        self::assertTrue($container->has('flag'));
        self::assertTrue($container->bound('flag'));
        self::assertTrue($container->provides('flag'));
        self::assertNull($container->get('flag'));
        self::assertTrue($container->isFrozen('flag'));
        self::assertTrue($container->isFrozen('feature.flag'));
    }

    public function test_load_registers_a_null_value_as_present(): void
    {
        $container = new Container();
        $container->load(['feature.flag' => ['value' => null]]);

        self::assertTrue($container->has('feature.flag'));
        self::assertTrue($container->bound('feature.flag'));
        self::assertNull($container->get('feature.flag'));
        self::assertTrue($container->isFrozen('feature.flag'));
    }

    public function test_a_scope_null_shadows_its_parent_through_an_inherited_alias(): void
    {
        $container = new Container();
        $container->set('transport', 'smtp');
        $container->alias('mailer.transport', 'transport');
        $scope = $container->createScope();
        $scope->set('transport', null);

        self::assertNull($scope->get('mailer.transport'));
        self::assertTrue($scope->isFrozen('transport'));
        self::assertFalse($container->isFrozen('transport'));
        self::assertSame('smtp', $container->get('transport'));
    }

    public function test_a_parent_null_remains_registered_and_is_frozen_by_a_scope_read(): void
    {
        $container = new Container();
        $container->set('feature.flag', null);
        $scope = $container->createScope();

        self::assertTrue($scope->has('feature.flag'));
        self::assertTrue($scope->bound('feature.flag'));
        self::assertTrue($scope->provides('feature.flag'));
        self::assertNull($scope->get('feature.flag'));
        self::assertTrue($container->isFrozen('feature.flag'));
    }

    public function test_removing_a_scope_null_restores_the_parent_value(): void
    {
        $container = new Container();
        $container->set('feature.flag', true);
        $scope = $container->createScope();
        $scope->set('feature.flag', null);

        self::assertNull($scope->get('feature.flag'));
        $scope->remove('feature.flag');

        self::assertTrue($scope->get('feature.flag'));
    }

    #[DataProvider('conditionalRegistrationProvider')]
    public function test_conditional_registration_does_not_replace_a_stored_null(string $method): void
    {
        $container = new Container();
        $container->set(RepositoryInterface::class, null);
        $container->{$method}(RepositoryInterface::class, InMemoryRepository::class);

        self::assertArrayNotHasKey(RepositoryInterface::class, $container->getBindings());
        self::assertNull($container->get(RepositoryInterface::class));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function conditionalRegistrationProvider(): iterable
    {
        yield 'bindIf' => ['bindIf'];
        yield 'singletonIf' => ['singletonIf'];
    }

    public function test_a_shared_definition_returning_null_remains_present_and_can_be_rebuilt(): void
    {
        $container = new Container();
        $calls = 0;
        $container->set('feature.flag', static function () use (&$calls): null {
            ++$calls;

            return null;
        });

        self::assertNull($container->get('feature.flag'));
        self::assertTrue($container->has('feature.flag'));
        self::assertTrue($container->bound('feature.flag'));
        self::assertTrue($container->isFrozen('feature.flag'));
        self::assertNull($container->get('feature.flag'));
        self::assertSame(1, $calls);
        $container->forget('feature.flag');
        self::assertNull($container->get('feature.flag'));
        self::assertSame(2, $calls);
    }

    public function test_a_factory_returning_null_is_still_invoked_for_each_read(): void
    {
        $container = new Container();
        $calls = 0;
        $container->set('feature.flag', $container->factory(static function () use (&$calls): null {
            ++$calls;

            return null;
        }));

        self::assertNull($container->get('feature.flag'));
        self::assertNull($container->get('feature.flag'));
        self::assertSame(2, $calls);
        self::assertTrue($container->has('feature.flag'));
    }

    public function test_remove_discards_a_null_registration_and_its_frozen_state(): void
    {
        $container = new Container();
        $container->set('feature.flag', null);
        $container->get('feature.flag');
        $container->remove('feature.flag');

        self::assertFalse($container->has('feature.flag'));
        self::assertFalse($container->bound('feature.flag'));
        self::assertFalse($container->isFrozen('feature.flag'));
        self::assertNotContains('feature.flag', $container->getRegisteredServices());
    }
}
