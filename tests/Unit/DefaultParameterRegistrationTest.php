<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Closure;
use Gacela\Container\Container;
use Gacela\Container\DependencyResolver;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\ConstructionCounter;
use GacelaTest\Fake\InMemoryRepository;
use GacelaTest\Fake\LazyService;
use GacelaTest\Fake\OuterHoldingDefaultedClassService;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\ServiceWithDefaultedClassDependency;
use GacelaTest\Fake\ServiceWithOptionalRepository;
use GacelaTest\Fake\SingletonAttributeService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

final class DefaultParameterRegistrationTest extends TestCase
{
    #[DataProvider('classRegistrations')]
    public function test_class_registrations_override_defaults_at_every_depth(Closure $register): void
    {
        $container = new Container();
        $register($container);

        for ($i = 0; $i < 3; ++$i) {
            self::assertInstanceOf(ClassWithoutDependencies::class, $container->get(ServiceWithDefaultedClassDependency::class)->dependency);
            self::assertInstanceOf(ClassWithoutDependencies::class, $container->get(OuterHoldingDefaultedClassService::class)->inner->dependency);
        }
    }

    /**
     * @return iterable<string, array{Closure(Container): void}>
     */
    public static function classRegistrations(): iterable
    {
        yield 'class binding' => [static function (Container $container): void {
            $container->bind(ClassWithoutDependencies::class, ClassWithoutDependencies::class);
        }];
        yield 'closure binding' => [static function (Container $container): void {
            $container->bind(ClassWithoutDependencies::class, static fn (): ClassWithoutDependencies => new ClassWithoutDependencies());
        }];
        yield 'class singleton' => [static function (Container $container): void {
            $container->singleton(ClassWithoutDependencies::class);
        }];
        yield 'closure singleton' => [static function (Container $container): void {
            $container->singleton(ClassWithoutDependencies::class, static fn (): ClassWithoutDependencies => new ClassWithoutDependencies());
        }];
        yield 'stored instance' => [static function (Container $container): void {
            $container->set(ClassWithoutDependencies::class, new ClassWithoutDependencies());
        }];
        yield 'stored factory' => [static function (Container $container): void {
            $container->set(ClassWithoutDependencies::class, $container->factory(static fn (): ClassWithoutDependencies => new ClassWithoutDependencies()));
        }];
        yield 'alias' => [static function (Container $container): void {
            $container->alias(ClassWithoutDependencies::class, 'configured');
            $container->set('configured', new ClassWithoutDependencies());
        }];
        yield 'lazy registration' => [static function (Container $container): void {
            $container->lazy(ClassWithoutDependencies::class);
        }];
        yield 'value definition' => [static function (Container $container): void {
            $container->load([ClassWithoutDependencies::class => ['value' => new ClassWithoutDependencies()]]);
        }];
    }

    public function test_an_interface_binding_supplies_an_optional_dependency(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, InMemoryRepository::class);

        self::assertInstanceOf(InMemoryRepository::class, $container->get(ServiceWithOptionalRepository::class)->repository);
    }

    public function test_an_alias_to_an_autowirable_target_supplies_the_optional_dependency(): void
    {
        $container = new Container();
        $container->alias(RepositoryInterface::class, InMemoryRepository::class);
        $scope = $container->createScope();

        self::assertFalse($container->provides(RepositoryInterface::class));
        self::assertInstanceOf(InMemoryRepository::class, $container->get(ServiceWithOptionalRepository::class)->repository);
        self::assertInstanceOf(InMemoryRepository::class, $scope->get(ServiceWithOptionalRepository::class)->repository);
    }

    public function test_typed_contextual_selection_beats_both_the_default_and_global_registration(): void
    {
        $container = new Container();
        $expected = new InMemoryRepository();
        $container->set(RepositoryInterface::class, null);
        $container->when(ServiceWithOptionalRepository::class)->needs(RepositoryInterface::class)->give($expected);

        self::assertSame($expected, $container->get(ServiceWithOptionalRepository::class)->repository);
        self::assertFalse($container->isFrozen(RepositoryInterface::class));
    }

    public function test_a_stored_null_is_read_and_frozen_instead_of_using_the_default(): void
    {
        $container = new Container();
        $container->set(ClassWithoutDependencies::class, null);

        self::assertNull($container->get(ServiceWithDefaultedClassDependency::class)->dependency);
        self::assertTrue($container->isFrozen(ClassWithoutDependencies::class));
    }

    public function test_scope_registration_ownership_is_preserved_for_optional_dependencies(): void
    {
        $container = new Container();
        $scope = $container->createScope();
        $expected = new ClassWithoutDependencies();
        $container->set(ClassWithoutDependencies::class, $expected);

        self::assertSame($expected, $scope->get(OuterHoldingDefaultedClassService::class)->inner->dependency);
        self::assertTrue($container->isFrozen(ClassWithoutDependencies::class));
        $scope->set(ClassWithoutDependencies::class, null);
        self::assertNull($scope->get(ServiceWithDefaultedClassDependency::class)->dependency);
        self::assertTrue($scope->isFrozen(ClassWithoutDependencies::class));
        self::assertSame($expected, $container->get(ClassWithoutDependencies::class));
    }

    public function test_a_resolved_attribute_singleton_is_used_instead_of_a_callable_default(): void
    {
        $container = new Container();
        $expected = $container->get(SingletonAttributeService::class);

        self::assertSame($expected, $container->resolve(static fn (?SingletonAttributeService $service = null): ?SingletonAttributeService => $service));
    }

    public function test_registered_factories_keep_their_lifetime_for_optional_dependencies(): void
    {
        $container = new Container();
        $calls = 0;
        $container->bind(ClassWithoutDependencies::class, static function () use (&$calls): ClassWithoutDependencies {
            ++$calls;

            return new ClassWithoutDependencies();
        });
        $first = $container->get(ServiceWithDefaultedClassDependency::class)->dependency;
        $second = $container->get(OuterHoldingDefaultedClassService::class)->inner->dependency;

        self::assertInstanceOf(ClassWithoutDependencies::class, $first);
        self::assertInstanceOf(ClassWithoutDependencies::class, $second);
        self::assertNotSame($first, $second);
        self::assertSame(2, $calls);
    }

    public function test_registered_singletons_keep_their_lifetime_for_optional_dependencies(): void
    {
        $container = new Container();
        $calls = 0;
        $container->singleton(ClassWithoutDependencies::class, static function () use (&$calls): ClassWithoutDependencies {
            ++$calls;

            return new ClassWithoutDependencies();
        });
        $first = $container->get(ServiceWithDefaultedClassDependency::class)->dependency;
        $second = $container->get(OuterHoldingDefaultedClassService::class)->inner->dependency;

        self::assertInstanceOf(ClassWithoutDependencies::class, $first);
        self::assertSame($first, $second);
        self::assertSame(1, $calls);
    }

    public function test_an_object_default_is_used_only_without_a_registration(): void
    {
        $container = new Container();
        $resolve = static fn (Person $person = new Person('fallback')): Person => $person;

        self::assertSame('fallback', $container->resolve($resolve)->name);
        $expected = new Person('configured');
        $container->set(Person::class, $expected);
        self::assertSame($expected, $container->resolve($resolve));
    }

    #[DataProvider('lazyModes')]
    public function test_an_optional_explicit_lazy_registration_keeps_its_construction_policy(bool $forceEager): void
    {
        if (!$forceEager && !method_exists(ReflectionClass::class, 'newLazyGhost')) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        ConstructionCounter::reset();
        $available = new ReflectionProperty(DependencyResolver::class, 'lazyObjectsAvailable');
        $previous = $available->getValue();
        if ($forceEager) {
            $available->setValue(null, false);
        }

        try {
            $container = new Container();
            $resolve = static fn (?LazyService $service = null): ?LazyService => $service;
            self::assertNull($container->resolve($resolve));
            $container->lazy(LazyService::class);
            $service = $container->resolve($resolve);

            self::assertInstanceOf(LazyService::class, $service);
            self::assertSame($forceEager ? 1 : 0, ConstructionCounter::countFor(LazyService::class));
            self::assertInstanceOf(ClassWithoutDependencies::class, $service->dependency);
            self::assertSame(1, ConstructionCounter::countFor(LazyService::class));
        } finally {
            if ($forceEager) {
                $available->setValue(null, $previous);
            }
        }
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function lazyModes(): iterable
    {
        yield 'native' => [false];
        yield 'eager fallback' => [true];
    }

    public function test_a_named_contextual_default_still_overrides_type_registration(): void
    {
        $container = new Container();
        $container->set(ClassWithoutDependencies::class, new ClassWithoutDependencies());
        $container->when(ServiceWithDefaultedClassDependency::class)->needs('$dependency')->give(null);

        self::assertNull($container->get(ServiceWithDefaultedClassDependency::class)->dependency);
        self::assertFalse($container->isFrozen(ClassWithoutDependencies::class));
    }

    public function test_a_failing_registration_does_not_silently_fall_back_to_the_default(): void
    {
        $container = new Container();
        $failure = new RuntimeException('registration failed');
        $container->bind(ClassWithoutDependencies::class, static fn (): never => throw $failure);
        $this->expectExceptionObject($failure);

        $container->get(ServiceWithDefaultedClassDependency::class);
    }

    public function test_late_registration_and_removal_work_after_warmup_and_factory_installation(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'optional-factories-');
        self::assertIsString($file);

        try {
            $generator = new Container();
            $compiled = $generator->writeCompiledFactories([
                OuterHoldingDefaultedClassService::class,
                ServiceWithDefaultedClassDependency::class,
                ClassWithoutDependencies::class,
            ], $file);
            self::assertContains(ClassWithoutDependencies::class, $compiled);
            self::assertNotContains(ServiceWithDefaultedClassDependency::class, $compiled);
            $container = new Container();
            $container->useCompiledFactories(Container::loadCompiledFactories($file));

            for ($i = 0; $i < 3; ++$i) {
                self::assertNull($container->get(OuterHoldingDefaultedClassService::class)->inner->dependency);
            }

            $expected = new ClassWithoutDependencies();
            $container->set(ClassWithoutDependencies::class, $expected);
            self::assertSame($expected, $container->get(ServiceWithDefaultedClassDependency::class)->dependency);
            self::assertSame($expected, $container->get(OuterHoldingDefaultedClassService::class)->inner->dependency);

            $container->remove(ClassWithoutDependencies::class);
            self::assertNull($container->get(ServiceWithDefaultedClassDependency::class)->dependency);
            self::assertNull($container->get(OuterHoldingDefaultedClassService::class)->inner->dependency);
        } finally {
            unlink($file);
        }
    }
}
