<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\Exception\ContainerException;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\PersonInterface;
use GacelaTest\Fake\RepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_an_alias_to_an_unregistered_class_is_bound_but_its_target_is_not(): void
    {
        $container = new Container();
        $container->alias('service', ClassWithoutDependencies::class);

        self::assertTrue($container->bound('service'));
        self::assertFalse($container->bound(ClassWithoutDependencies::class));
        self::assertInstanceOf(ClassWithoutDependencies::class, $container->get('service'));
        self::assertFalse($container->bound(ClassWithoutDependencies::class));
    }

    public function test_an_alias_chain_is_registered_even_when_its_target_is_missing(): void
    {
        $container = new Container();
        $container->alias('service', 'middle');
        $container->alias('middle', 'missing');

        self::assertTrue($container->bound('service'));
        self::assertTrue($container->bound('middle'));
        self::assertFalse($container->bound('missing'));
        self::assertFalse($container->has('service'));
    }

    #[DataProvider('conditionalMethods')]
    public function test_conditional_registration_does_not_write_behind_an_alias(string $method): void
    {
        $container = new Container();
        $container->alias('service', ClassWithoutDependencies::class);
        $container->{$method}('service', Person::class);

        self::assertSame([], $container->getBindings());
        self::assertInstanceOf(ClassWithoutDependencies::class, $container->get('service'));
    }

    #[DataProvider('conditionalMethods')]
    public function test_late_inherited_aliases_block_conditional_registration_in_a_scope(string $method): void
    {
        $container = new Container();
        $scope = $container->createScope();
        $container->alias('service', ClassWithoutDependencies::class);
        $scope->{$method}('service', Person::class);

        self::assertTrue($scope->bound('service'));
        self::assertSame([], $scope->getBindings());
        self::assertSame([], $container->getBindings());
        self::assertInstanceOf(ClassWithoutDependencies::class, $scope->get('service'));
    }

    public function test_a_scope_local_alias_is_bound_without_registering_its_target(): void
    {
        $container = new Container();
        $container->alias('service', 'missing');
        $scope = $container->createScope();
        $scope->alias('service', ClassWithoutDependencies::class);

        self::assertTrue($scope->bound('service'));
        self::assertFalse($scope->bound(ClassWithoutDependencies::class));
        self::assertInstanceOf(ClassWithoutDependencies::class, $scope->get('service'));
        self::assertFalse($container->has('service'));
    }

    #[DataProvider('conditionalMethods')]
    public function test_a_cyclic_alias_rejects_conditional_registration(string $method): void
    {
        $container = new Container();
        $container->alias('first', 'second');
        $container->alias('second', 'first');
        $this->expectException(ContainerException::class);

        $container->{$method}('first', ClassWithoutDependencies::class);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function conditionalMethods(): iterable
    {
        yield 'bindIf' => ['bindIf'];
        yield 'singletonIf' => ['singletonIf'];
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
