<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\Exception\ContainerException;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\ServiceWithMixedParameter;
use GacelaTest\Fake\ServiceWithNullableDependency;
use PHPUnit\Framework\TestCase;

/**
 * null is a value like any other. It was invisible because the lookup used
 * isset(), which calls a bound null absent.
 */
final class GiveNullTest extends TestCase
{
    protected function setUp(): void
    {
        Container::resetStaticCaches();
    }

    protected function tearDown(): void
    {
        Container::resetStaticCaches();
    }

    public function test_give_null_injects_null_for_a_named_parameter(): void
    {
        $container = new Container();
        $container->when(ServiceWithMixedParameter::class)->needs('$config')->give(null);

        self::assertNull($container->get(ServiceWithMixedParameter::class)->config);
    }

    public function test_give_null_satisfies_a_nullable_class_parameter(): void
    {
        $container = new Container();
        $container->when(ServiceWithNullableDependency::class)->needs('$repository')->give(null);

        self::assertNull($container->get(ServiceWithNullableDependency::class)->repository);
    }

    public function test_a_non_null_named_binding_still_works(): void
    {
        $container = new Container();
        $container->when(ServiceWithMixedParameter::class)->needs('$config')->give('value');

        self::assertSame('value', $container->get(ServiceWithMixedParameter::class)->config);
    }

    public function test_other_falsy_values_still_work(): void
    {
        foreach ([false, 0, '', []] as $value) {
            $container = new Container();
            $container->when(ServiceWithMixedParameter::class)->needs('$config')->give($value);

            self::assertSame($value, $container->get(ServiceWithMixedParameter::class)->config);
        }
    }

    /**
     * Not binding a type already means "nothing is bound", so binding one to
     * null could only be a mistake — and a silent one, since it would simply
     * behave as though the call had not happened.
     */
    public function test_give_null_for_a_type_need_is_refused(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/cannot be null/');

        $container->when(ServiceWithNullableDependency::class)
            ->needs(RepositoryInterface::class)
            ->give(null);
    }

    public function test_the_refusal_suggests_the_named_form(): void
    {
        $container = new Container();

        try {
            $container->when(ServiceWithNullableDependency::class)
                ->needs(RepositoryInterface::class)
                ->give(null);
            self::fail('expected a refusal');
        } catch (ContainerException $exception) {
            self::assertStringContainsString('needs(', $exception->getMessage());
            self::assertStringContainsString('give(null)', $exception->getMessage());
        }
    }

    public function test_a_type_need_with_a_real_binding_is_unaffected(): void
    {
        $container = new Container();
        $container->when(ServiceWithNullableDependency::class)
            ->needs(RepositoryInterface::class)
            ->give(DatabaseRepository::class);

        self::assertInstanceOf(
            DatabaseRepository::class,
            $container->get(ServiceWithNullableDependency::class)->repository,
        );
    }

    public function test_a_closure_returning_null_still_works(): void
    {
        $container = new Container();
        $container->when(ServiceWithMixedParameter::class)
            ->needs('$config')
            ->give(static fn (): mixed => null);

        self::assertNull($container->get(ServiceWithMixedParameter::class)->config);
    }
}
