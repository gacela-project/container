<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use GacelaTest\Fake\ClassWithDependencyWithoutDependencies;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\ConsumerOfSingletonAttributeService;
use GacelaTest\Fake\InMemoryRepository;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\ServiceWithRepository;
use GacelaTest\Fake\SingletonAttributeService;
use PHPUnit\Framework\TestCase;

use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * A shared service is the same instance whether get() is asked for it or a
 * constructor parameter is. A scope always delegated the parameter to its
 * parent and got that; a root container built a fresh one.
 */
final class SingletonParameterTest extends TestCase
{
    protected function setUp(): void
    {
        Container::resetStaticCaches();
    }

    protected function tearDown(): void
    {
        Container::resetStaticCaches();
    }

    public function test_a_singleton_attribute_class_is_shared_as_a_parameter(): void
    {
        $container = new Container();

        $first = $container->get(ConsumerOfSingletonAttributeService::class)->service;

        self::assertSame($first, $container->get(SingletonAttributeService::class));
        self::assertSame($first, $container->get(ConsumerOfSingletonAttributeService::class)->service);
    }

    public function test_a_class_registered_with_singleton_is_shared_as_a_parameter(): void
    {
        $container = new Container();
        $container->singleton(ClassWithoutDependencies::class);

        $direct = $container->get(ClassWithoutDependencies::class);

        self::assertSame($direct, $container->get(ClassWithDependencyWithoutDependencies::class)->classWithoutDependencies);
        self::assertSame($direct, $container->make(ClassWithDependencyWithoutDependencies::class)->classWithoutDependencies);
    }

    public function test_an_abstract_registered_with_singleton_is_shared_as_a_parameter(): void
    {
        $container = new Container();
        $container->singleton(RepositoryInterface::class, InMemoryRepository::class);

        $first = $container->get(ServiceWithRepository::class)->repository;

        self::assertSame($first, $container->get(RepositoryInterface::class));
        self::assertSame($first, $container->get(ServiceWithRepository::class)->repository);
    }

    public function test_the_concrete_behind_a_singleton_abstract_is_shared_as_a_parameter(): void
    {
        $container = new Container();
        $container->singleton(RepositoryInterface::class, InMemoryRepository::class);

        self::assertSame(
            $container->get(InMemoryRepository::class),
            $container->resolve(static fn (InMemoryRepository $repository): InMemoryRepository => $repository),
        );
    }

    /**
     * Repeated resolutions of a consumer go through a composed builder after
     * the first, which must not flatten a shared parameter into a `new`.
     */
    public function test_a_singleton_parameter_stays_shared_across_repeated_resolutions(): void
    {
        $container = new Container();
        $shared = $container->get(SingletonAttributeService::class);

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame($shared, $container->get(ConsumerOfSingletonAttributeService::class)->service);
        }
    }

    public function test_a_scope_shares_a_singleton_parameter_it_resolved_itself(): void
    {
        $scope = (new Container())->createScope();

        self::assertSame(
            $scope->get(SingletonAttributeService::class),
            $scope->get(ConsumerOfSingletonAttributeService::class)->service,
        );
    }

    public function test_a_plan_cached_without_lifetime_flags_still_shares_a_singleton_parameter(): void
    {
        $container = new Container(compiledPlans: [
            SingletonAttributeService::class => [
                'instantiable' => true,
                'params' => (new Container())->compile([SingletonAttributeService::class])[SingletonAttributeService::class]['params'],
            ],
        ]);

        self::assertSame(
            $container->get(SingletonAttributeService::class),
            $container->get(ConsumerOfSingletonAttributeService::class)->service,
        );
    }

    public function test_a_singleton_parameter_is_not_compiled_into_its_consumer(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'gacela-singleton-param');
        self::assertIsString($file);

        $writer = new Container();
        $writer->singleton(ClassWithoutDependencies::class);
        $compiled = $writer->writeCompiledFactories([
            ClassWithDependencyWithoutDependencies::class,
            ConsumerOfSingletonAttributeService::class,
        ], $file);

        $container = new Container();
        $container->singleton(ClassWithoutDependencies::class);
        $container->useCompiledFactories(Container::loadCompiledFactories($file));
        unlink($file);

        self::assertSame([], $compiled);
        self::assertSame(
            $container->get(ClassWithoutDependencies::class),
            $container->get(ClassWithDependencyWithoutDependencies::class)->classWithoutDependencies,
        );
        self::assertSame(
            $container->get(SingletonAttributeService::class),
            $container->get(ConsumerOfSingletonAttributeService::class)->service,
        );
    }
}
