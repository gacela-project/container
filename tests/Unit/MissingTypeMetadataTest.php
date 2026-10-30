<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use App\Nope\Missing;
use Gacela\Container\ClassSource;
use Gacela\Container\Container;
use Gacela\Container\DependencyResolver;
use Gacela\Container\Exception\DependencyNotFoundException;
use Gacela\Container\PlanCache;
use GacelaTest\Fake\ClassWithMissingClassDependency;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\CompilableDependency;
use GacelaTest\Fake\InMemoryRepository;
use GacelaTest\Fake\MissingTypeInjectedConsumer;
use GacelaTest\Fake\RelativeTypeMetadataService;
use GacelaTest\Fake\RepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MissingTypeMetadataTest extends TestCase
{
    protected function setUp(): void
    {
        Container::resetStaticCaches();
        self::assertFalse(class_exists(Missing::class, false));
        self::assertFalse(interface_exists(Missing::class, false));
    }

    /**
     * @return iterable<string, array{class-string, list<string>, bool}>
     */
    public static function consumerModes(): iterable
    {
        foreach ([false, true] as $reset) {
            yield 'constructor' . ($reset ? ' reset' : '') => [ClassWithMissingClassDependency::class, ['missing'], $reset];
            yield 'property and setter' . ($reset ? ' reset' : '') => [MissingTypeInjectedConsumer::class, ['propertyDependency', 'setterDependency'], $reset];
        }
    }

    #[DataProvider('consumerModes')]
    public function test_existing_plans_recover_after_a_dependency_is_declared(string $consumer, array $properties, bool $reset): void
    {
        $container = new Container();
        self::failWhileMissing($container, $consumer);
        self::failWhileMissing($container, $consumer);
        self::assertTrue(class_alias(ClassWithoutDependencies::class, Missing::class));
        if ($reset) {
            Container::resetStaticCaches();
        }

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $service = $container->get($consumer);
            foreach ($properties as $property) {
                self::assertInstanceOf(ClassWithoutDependencies::class, $service->{$property});
            }
        }
    }

    public function test_a_missing_non_builtin_parameter_reports_a_missing_dependency(): void
    {
        $this->expectException(DependencyNotFoundException::class);

        (new Container())->get(ClassWithMissingClassDependency::class);
    }

    public function test_a_missing_non_builtin_property_reports_a_missing_dependency(): void
    {
        $this->expectException(DependencyNotFoundException::class);

        (new Container())->get(MissingTypeInjectedConsumer::class);
    }

    public function test_scope_and_unrelated_roots_can_reuse_the_initially_incomplete_shared_plan(): void
    {
        $plans = new PlanCache();
        $first = new Container([], [], [], $plans);
        $scope = $first->createScope();
        $second = new Container([], [], [], $plans);
        self::failWhileMissing($first, ClassWithMissingClassDependency::class);
        self::failWhileMissing($scope, ClassWithMissingClassDependency::class);
        self::failWhileMissing($second, ClassWithMissingClassDependency::class);
        self::assertTrue(class_alias(ClassWithoutDependencies::class, Missing::class));
        Container::resetStaticCaches();

        foreach ([$first, $scope, $second] as $container) {
            self::assertInstanceOf(ClassWithoutDependencies::class, $container->get(ClassWithMissingClassDependency::class)->missing);
        }
    }

    public function test_exported_plans_keep_late_type_resolution_available(): void
    {
        $first = new Container();
        self::failWhileMissing($first, ClassWithMissingClassDependency::class);
        $plans = $first->compile([]);
        self::assertArrayHasKey(ClassWithMissingClassDependency::class, $plans);
        $second = new Container([], [], $plans);
        self::assertTrue(class_alias(ClassWithoutDependencies::class, Missing::class));
        Container::resetStaticCaches();

        self::assertInstanceOf(ClassWithoutDependencies::class, $second->get(ClassWithMissingClassDependency::class)->missing);
    }

    public function test_persisted_plans_describe_the_type_without_requiring_its_declaration(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'late-type-plan-');
        self::assertIsString($file);

        try {
            $first = new Container();
            $first->writeCompiledCache(ClassSource::fromList([ClassWithMissingClassDependency::class]), $file);
            $plans = Container::loadCompiledCache($file);
            self::assertArrayHasKey(ClassWithMissingClassDependency::class, $plans);
            $second = new Container([], [], $plans);
            self::assertTrue(class_alias(ClassWithoutDependencies::class, Missing::class));
            Container::resetStaticCaches();

            self::assertInstanceOf(ClassWithoutDependencies::class, $second->get(ClassWithMissingClassDependency::class)->missing);
        } finally {
            unlink($file);
        }
    }

    public function test_factories_can_be_regenerated_from_the_original_plan_after_declaration(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'late-type-factory-');
        self::assertIsString($file);

        try {
            $container = new Container();
            $source = ClassSource::fromList([ClassWithMissingClassDependency::class]);
            self::assertSame([], $container->writeCompiledFactories($source, $file));
            self::assertTrue(class_alias(ClassWithoutDependencies::class, Missing::class));
            Container::resetStaticCaches();
            self::assertContains(ClassWithMissingClassDependency::class, $container->writeCompiledFactories($source, $file));
            $container->useCompiledFactories(Container::loadCompiledFactories($file));

            for ($attempt = 0; $attempt < 3; ++$attempt) {
                self::assertInstanceOf(ClassWithoutDependencies::class, $container->get(ClassWithMissingClassDependency::class)->missing);
            }
        } finally {
            unlink($file);
        }
    }

    public function test_a_late_interface_can_be_bound_after_the_plan_was_built(): void
    {
        $container = new Container();
        self::failWhileMissing($container, ClassWithMissingClassDependency::class);
        self::assertTrue(class_alias(RepositoryInterface::class, Missing::class));
        $container->bind(Missing::class, InMemoryRepository::class);
        Container::resetStaticCaches();

        self::assertInstanceOf(InMemoryRepository::class, $container->get(ClassWithMissingClassDependency::class)->missing);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function declarationKinds(): iterable
    {
        yield 'class' => [false];
        yield 'interface' => [true];
    }

    #[DataProvider('declarationKinds')]
    public function test_a_contextual_factory_can_make_the_type_available_before_returning_it(bool $interface): void
    {
        $container = new Container();
        $calls = 0;
        $container->when(ClassWithMissingClassDependency::class)->needs(Missing::class)->give(static function () use ($interface, &$calls): object {
            ++$calls;
            if ($calls === 1) {
                class_alias($interface ? RepositoryInterface::class : ClassWithoutDependencies::class, Missing::class);
            }

            return $interface ? new InMemoryRepository() : new ClassWithoutDependencies();
        });

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            self::assertInstanceOf($interface ? InMemoryRepository::class : ClassWithoutDependencies::class, $container->get(ClassWithMissingClassDependency::class)->missing);
        }
        self::assertSame(2, $calls);
    }

    public function test_a_cached_callable_plan_retries_the_non_builtin_type_after_declaration(): void
    {
        $container = new Container();
        $callback = static fn (Missing $dependency): Missing => $dependency;

        try {
            $container->resolve($callback);
            self::fail('The missing callable dependency should fail.');
        } catch (ContainerExceptionInterface) {
        }

        self::assertTrue(class_alias(ClassWithoutDependencies::class, Missing::class));
        Container::resetStaticCaches();
        self::assertInstanceOf(ClassWithoutDependencies::class, $container->resolve($callback));
    }

    public function test_an_optional_missing_type_can_receive_a_registration_after_declaration(): void
    {
        $container = new Container();
        $callback = static fn (?Missing $dependency = null): ?Missing => $dependency;
        self::assertNull($container->resolve($callback));
        self::assertTrue(class_alias(ClassWithoutDependencies::class, Missing::class));
        $expected = new ClassWithoutDependencies();
        $container->set(Missing::class, $expected);
        Container::resetStaticCaches();

        self::assertSame($expected, $container->resolve($callback));
        self::assertTrue($container->isFrozen(Missing::class));
    }

    public function test_self_and_parent_metadata_keep_the_declaring_class_meaning(): void
    {
        $container = new Container();
        $parent = new CompilableDependency();
        $self = new RelativeTypeMetadataService();
        $container->set(CompilableDependency::class, $parent);
        $container->when(RelativeTypeMetadataService::class)->needs(RelativeTypeMetadataService::class)->give($self);
        $service = $container->get(RelativeTypeMetadataService::class);

        self::assertSame($parent, $service->constructorParent);
        self::assertSame($self, $service->selfDependency);
        self::assertSame($parent, $service->parentDependency);
        self::assertSame($self, $service->setterSelf);
        self::assertSame($parent, $service->setterParent);
    }

    public function test_class_scoped_callable_self_and_parent_types_keep_their_meaning(): void
    {
        $container = new Container();
        $self = new RelativeTypeMetadataService();
        $parent = new CompilableDependency();
        $container->set(RelativeTypeMetadataService::class, $self);
        $container->set(CompilableDependency::class, $parent);

        self::assertSame([$self, $parent], $container->resolve(RelativeTypeMetadataService::callback()));
    }

    public function test_a_standalone_resolver_reuses_its_plan_after_declaration(): void
    {
        $resolver = new DependencyResolver();
        try {
            $resolver->resolveDependencies(ClassWithMissingClassDependency::class);
            self::fail('The missing constructor dependency should fail.');
        } catch (ContainerExceptionInterface) {
        }

        self::assertTrue(class_alias(ClassWithoutDependencies::class, Missing::class));
        Container::resetStaticCaches();
        self::assertInstanceOf(ClassWithoutDependencies::class, $resolver->resolveDependencies(ClassWithMissingClassDependency::class)[0]);
    }

    /**
     * @param class-string $consumer
     */
    private static function failWhileMissing(Container $container, string $consumer): void
    {
        try {
            $container->get($consumer);
            self::fail('The undeclared dependency should not resolve.');
        } catch (ContainerExceptionInterface) {
        }
    }
}
