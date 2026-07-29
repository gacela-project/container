<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Closure;
use Gacela\Container\Container;
use Gacela\Container\ContainerInterface;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\ExpensiveReportGenerator;
use GacelaTest\Fake\InMemoryRepository;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\ServiceWithRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use WeakReference;

use function gc_collect_cycles;
use function gc_disable;
use function gc_enable;
use function gc_enabled;
use function method_exists;

/**
 * A container and its collaborators used to point at each other, so dropping one
 * released it only when the cycle collector next ran. Under a long-running worker
 * that is unpredictable, and under gc_disable() it never happens — which
 * contradicts what createScope() documents.
 *
 * Every test here runs with the collector off, so a container that is still
 * reachable stays reachable and WeakReference::get() answers the only question
 * that matters: did refcounting alone free it?
 */
final class ContainerReleaseTest extends TestCase
{
    private bool $collectorWasEnabled = true;

    protected function setUp(): void
    {
        $this->collectorWasEnabled = gc_enabled();
        gc_disable();
    }

    protected function tearDown(): void
    {
        if ($this->collectorWasEnabled) {
            gc_enable();
        }

        gc_collect_cycles();
    }

    public function test_a_container_that_resolved_nothing_is_released(): void
    {
        $container = new Container();

        $reference = WeakReference::create($container);
        unset($container);

        self::assertNull($reference->get());
    }

    public function test_a_container_that_resolved_a_graph_is_released(): void
    {
        $container = new Container([RepositoryInterface::class => InMemoryRepository::class]);
        $container->get(ServiceWithRepository::class);

        $reference = WeakReference::create($container);
        unset($container);

        self::assertNull($reference->get());
    }

    public function test_a_container_with_a_closure_binding_is_released(): void
    {
        $container = new Container([
            RepositoryInterface::class => static fn (): RepositoryInterface => new InMemoryRepository(),
        ]);
        $container->get(RepositoryInterface::class);

        $reference = WeakReference::create($container);
        unset($container);

        self::assertNull($reference->get());
    }

    /**
     * The case the scopes doc describes: a worker creating one scope per request.
     */
    public function test_a_dropped_scope_is_released_while_its_parent_lives(): void
    {
        $parent = new Container();
        $scope = $parent->createScope();
        $scope->get(ClassWithoutDependencies::class);

        $reference = WeakReference::create($scope);
        unset($scope);

        self::assertNull($reference->get());
        self::assertInstanceOf(ClassWithoutDependencies::class, $parent->get(ClassWithoutDependencies::class));
    }

    /**
     * Scopes are tracked by the parent through WeakReference already, so a
     * released scope must leave no live entry behind either.
     */
    public function test_a_parent_that_created_scopes_is_released_with_them(): void
    {
        $parent = new Container();
        $parent->createScope()->get(ClassWithoutDependencies::class);

        $reference = WeakReference::create($parent);
        unset($parent);

        self::assertNull($reference->get());
    }

    /**
     * Weakening the back-pointer must not change what a binding is handed: the
     * closure runs while the container is resolving, so it is always alive.
     */
    public function test_a_closure_binding_still_receives_the_container_that_owns_it(): void
    {
        $received = null;
        $container = new Container([
            RepositoryInterface::class => static function (ContainerInterface $container) use (&$received): RepositoryInterface {
                $received = $container;

                return new InMemoryRepository();
            },
        ]);

        $container->get(RepositoryInterface::class);

        self::assertSame($container, $received);
    }

    public function test_a_nested_closure_binding_still_receives_the_container(): void
    {
        $received = null;
        $container = new Container([
            RepositoryInterface::class => static function (ContainerInterface $container) use (&$received): RepositoryInterface {
                $received = $container;

                return new InMemoryRepository();
            },
        ]);

        $container->get(ServiceWithRepository::class);

        self::assertSame($container, $received);
    }

    /**
     * The one case where a weak back-pointer would be observable: initializing a
     * lazy object runs resolution *after* get() returned, so an untouched one has
     * to keep its container alive or that first touch hands null to a factory
     * typed against ContainerInterface.
     */
    public function test_an_untouched_lazy_proxy_keeps_its_container_alive(): void
    {
        if (!self::supportsLazyObjects()) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $container = new Container();
        $container->lazy(ExpensiveReportGenerator::class, self::reportGeneratorFactory());
        $untouched = $container->get(ExpensiveReportGenerator::class);

        $reference = WeakReference::create($container);
        unset($container);

        self::assertNotNull($reference->get());
        self::assertInstanceOf(ClassWithoutDependencies::class, $untouched->dependency);
    }

    public function test_an_untouched_lazy_ghost_keeps_its_container_alive(): void
    {
        if (!self::supportsLazyObjects()) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $received = null;
        $container = new Container([
            RepositoryInterface::class => static function (ContainerInterface $container) use (&$received): RepositoryInterface {
                $received = $container;

                return new InMemoryRepository();
            },
        ]);
        $container->lazy(ServiceWithRepository::class);
        $untouched = $container->get(ServiceWithRepository::class);

        $reference = WeakReference::create($container);
        unset($container);

        self::assertNotNull($reference->get());

        // Initializes the ghost, whose constructor reaches the closure binding.
        self::assertInstanceOf(InMemoryRepository::class, $untouched->repository);
        self::assertNotNull($received);
    }

    /**
     * The hold must end when the lazy object is initialized, not last for the
     * object's whole life — otherwise handing out one lazy service pins the
     * container just as the cycle did.
     */
    public function test_an_initialized_lazy_proxy_releases_its_container(): void
    {
        if (!self::supportsLazyObjects()) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $container = new Container();
        $container->lazy(ExpensiveReportGenerator::class, self::reportGeneratorFactory());
        $initialized = $container->get(ExpensiveReportGenerator::class);
        $initialized->dependency;

        $reference = WeakReference::create($container);
        unset($container);

        self::assertNull($reference->get());
    }

    public function test_an_initialized_lazy_ghost_releases_its_container(): void
    {
        if (!self::supportsLazyObjects()) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $container = new Container([RepositoryInterface::class => InMemoryRepository::class]);
        $container->lazy(ServiceWithRepository::class);
        $initialized = $container->get(ServiceWithRepository::class);
        $initialized->repository;

        $reference = WeakReference::create($container);
        unset($container);

        self::assertNull($reference->get());
    }

    public function test_a_contextual_closure_binding_still_receives_the_container(): void
    {
        $received = null;
        $container = new Container();
        $container->when(ServiceWithRepository::class)
            ->needs(RepositoryInterface::class)
            ->give(static function (ContainerInterface $container) use (&$received): RepositoryInterface {
                $received = $container;

                return new InMemoryRepository();
            });

        $container->get(ServiceWithRepository::class);

        self::assertSame($container, $received);
    }

    private static function reportGeneratorFactory(): Closure
    {
        return static fn (ContainerInterface $container): ExpensiveReportGenerator => new ExpensiveReportGenerator(new ClassWithoutDependencies());
    }

    private static function supportsLazyObjects(): bool
    {
        return method_exists(ReflectionClass::class, 'newLazyGhost');
    }
}
