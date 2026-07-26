<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use GacelaTest\Fake\AbstractService;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\PersonInterface;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\ServiceWithRepository;
use PHPUnit\Framework\TestCase;

/**
 * has() answers the PSR-11 question "will get() resolve this?".
 * bound() answers the narrower "was this explicitly registered?".
 */
final class HasSemanticsTest extends TestCase
{
    public function test_has_is_true_for_an_autowirable_class(): void
    {
        $container = new Container();

        self::assertTrue($container->has(ClassWithoutDependencies::class));
    }

    public function test_has_is_true_for_an_autowirable_class_with_dependencies(): void
    {
        $container = new Container([RepositoryInterface::class => DatabaseRepository::class]);

        self::assertTrue($container->has(ServiceWithRepository::class));
    }

    public function test_has_is_false_for_an_unbound_interface(): void
    {
        $container = new Container();

        self::assertFalse($container->has(PersonInterface::class));
    }

    public function test_has_is_true_for_a_bound_interface(): void
    {
        $container = new Container([RepositoryInterface::class => DatabaseRepository::class]);

        self::assertTrue($container->has(RepositoryInterface::class));
    }

    public function test_has_is_false_for_an_abstract_class(): void
    {
        $container = new Container();

        self::assertFalse($container->has(AbstractService::class));
    }

    public function test_has_is_false_for_an_unknown_id(): void
    {
        $container = new Container();

        self::assertFalse($container->has('not-a-class'));
    }

    public function test_has_is_true_for_a_registered_instance(): void
    {
        $container = new Container();
        $container->set('service', new ClassWithoutDependencies());

        self::assertTrue($container->has('service'));
    }

    public function test_has_resolves_aliases(): void
    {
        $container = new Container();
        $container->alias('repo', RepositoryInterface::class);
        $container->bind(RepositoryInterface::class, DatabaseRepository::class);

        self::assertTrue($container->has('repo'));
    }

    public function test_has_agrees_with_get_for_an_autowirable_class(): void
    {
        $container = new Container();

        self::assertTrue($container->has(ClassWithoutDependencies::class));
        self::assertInstanceOf(ClassWithoutDependencies::class, $container->get(ClassWithoutDependencies::class));
    }

    public function test_array_access_isset_mirrors_has(): void
    {
        $container = new Container();

        self::assertTrue(isset($container[ClassWithoutDependencies::class]));
        self::assertFalse(isset($container[PersonInterface::class]));
        self::assertFalse(isset($container['not-a-class']));
    }

    public function test_bound_stays_narrower_than_has(): void
    {
        $container = new Container();

        // Autowirable, so resolvable, but never explicitly registered.
        self::assertTrue($container->has(ClassWithoutDependencies::class));
        self::assertFalse($container->bound(ClassWithoutDependencies::class));
    }

    public function test_widening_has_does_not_turn_transient_resolution_into_shared(): void
    {
        $container = new Container();

        $first = $container->get(ClassWithoutDependencies::class);
        $second = $container->get(ClassWithoutDependencies::class);

        self::assertNotSame($first, $second);
    }

    public function test_a_set_instance_is_still_returned_as_the_same_instance(): void
    {
        $container = new Container();
        $instance = new ClassWithoutDependencies();
        $container->set('service', $instance);

        self::assertSame($instance, $container->get('service'));
        self::assertSame($instance, $container->get('service'));
    }
}
