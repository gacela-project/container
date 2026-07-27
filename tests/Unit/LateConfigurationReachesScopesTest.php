<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use GacelaTest\Fake\ConstructionCounter;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\ExpensiveReportGenerator;
use GacelaTest\Fake\InMemoryRepository;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\ServiceWithRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use WeakReference;

use function gc_collect_cycles;
use function method_exists;

/**
 * A scope copies its parent's contextual bindings, generated factories and
 * lazy() registrations when it is created, for hot-path reasons that have not
 * changed. What did change is the failure mode: configuration registered after
 * a scope exists used to be silently invisible to it, so a when() call landing
 * after boot-ordering drift kept injecting the wrong implementation with a
 * green test suite. It is pushed down now.
 */
final class LateConfigurationReachesScopesTest extends TestCase
{
    protected function setUp(): void
    {
        ConstructionCounter::reset();
    }

    public function test_a_contextual_binding_registered_after_a_scope_exists_reaches_it(): void
    {
        $container = new Container();
        $scope = $container->createScope();

        $container->when(ServiceWithRepository::class)
            ->needs(RepositoryInterface::class)
            ->give(InMemoryRepository::class);

        self::assertInstanceOf(
            InMemoryRepository::class,
            $scope->get(ServiceWithRepository::class)->repository,
        );
    }

    public function test_it_reaches_scopes_of_scopes(): void
    {
        $container = new Container();
        $nested = $container->createScope()->createScope();

        $container->when(ServiceWithRepository::class)
            ->needs(RepositoryInterface::class)
            ->give(InMemoryRepository::class);

        self::assertInstanceOf(
            InMemoryRepository::class,
            $nested->get(ServiceWithRepository::class)->repository,
        );
    }

    public function test_a_scopes_own_binding_outranks_one_pushed_down_later(): void
    {
        $container = new Container();
        $scope = $container->createScope();

        $scope->when(ServiceWithRepository::class)
            ->needs(RepositoryInterface::class)
            ->give(DatabaseRepository::class);

        $container->when(ServiceWithRepository::class)
            ->needs(RepositoryInterface::class)
            ->give(InMemoryRepository::class);

        // Shadowing works the way it does everywhere else: what the scope
        // registered for itself is not a stale copy of the parent's.
        self::assertInstanceOf(
            DatabaseRepository::class,
            $scope->get(ServiceWithRepository::class)->repository,
        );
        self::assertInstanceOf(
            InMemoryRepository::class,
            $container->get(ServiceWithRepository::class)->repository,
        );
    }

    public function test_a_scope_does_not_push_its_own_bindings_up(): void
    {
        $container = new Container();
        $scope = $container->createScope();

        $scope->when(ServiceWithRepository::class)
            ->needs(RepositoryInterface::class)
            ->give(InMemoryRepository::class);

        // The direction is one-way: a scope configuring itself never mutates
        // the container it came from.
        self::assertSame([], $container->getBindings());
        self::assertInstanceOf(
            InMemoryRepository::class,
            $scope->get(ServiceWithRepository::class)->repository,
        );
    }

    public function test_a_binding_replaced_on_the_parent_replaces_the_inherited_copy(): void
    {
        $container = new Container();
        $container->when(ServiceWithRepository::class)
            ->needs(RepositoryInterface::class)
            ->give(InMemoryRepository::class);

        $scope = $container->createScope();

        $container->when(ServiceWithRepository::class)
            ->needs(RepositoryInterface::class)
            ->give(DatabaseRepository::class);

        // The scope holds a copy of the old value, which must not be mistaken
        // for a decision the scope made.
        self::assertInstanceOf(
            DatabaseRepository::class,
            $scope->get(ServiceWithRepository::class)->repository,
        );
    }

    public function test_generated_factories_installed_after_a_scope_exists_reach_it(): void
    {
        $container = new Container();
        $scope = $container->createScope();

        $calls = 0;
        $container->useCompiledFactories([
            InMemoryRepository::class => static function () use (&$calls): InMemoryRepository {
                ++$calls;
                return new InMemoryRepository();
            },
        ]);

        $scope->get(InMemoryRepository::class);

        self::assertSame(1, $calls);
    }

    public function test_a_scopes_own_factories_outrank_ones_pushed_down_later(): void
    {
        $container = new Container();
        $scope = $container->createScope();

        $scopeCalls = 0;
        $scope->useCompiledFactories([
            InMemoryRepository::class => static function () use (&$scopeCalls): InMemoryRepository {
                ++$scopeCalls;
                return new InMemoryRepository();
            },
        ]);

        $parentCalls = 0;
        $container->useCompiledFactories([
            InMemoryRepository::class => static function () use (&$parentCalls): InMemoryRepository {
                ++$parentCalls;
                return new InMemoryRepository();
            },
        ]);

        $scope->get(InMemoryRepository::class);

        self::assertSame(1, $scopeCalls);
        self::assertSame(0, $parentCalls);
    }

    public function test_a_lazy_registration_made_after_a_scope_exists_reaches_it(): void
    {
        if (!method_exists(ReflectionClass::class, 'newLazyGhost')) {
            // On PHP 8.3 a lazy target is constructed eagerly whether or not
            // the registration arrived, so there is nothing to observe.
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $container = new Container();
        $scope = $container->createScope();

        $container->lazy(ExpensiveReportGenerator::class);

        $instance = $scope->get(ExpensiveReportGenerator::class);

        // The registration is the only thing saying the class is lazy, so a
        // scope that missed it would construct eagerly — unobservable except
        // for the timing, which is the whole reason lazy() was called.
        self::assertSame(0, ConstructionCounter::countFor(ExpensiveReportGenerator::class));
        self::assertInstanceOf(ExpensiveReportGenerator::class, $instance);
    }

    public function test_a_dropped_scope_does_not_keep_being_configured(): void
    {
        $container = new Container();

        $scope = $container->createScope();
        $handle = WeakReference::create($scope);
        unset($scope);
        gc_collect_cycles();

        // The handle a parent keeps on its scopes is weak: a dropped scope is
        // collected exactly as it was before, which is what makes a scope
        // usable as a request lifetime.
        self::assertNull($handle->get());

        $container->when(ServiceWithRepository::class)
            ->needs(RepositoryInterface::class)
            ->give(InMemoryRepository::class);

        self::assertInstanceOf(
            InMemoryRepository::class,
            $container->get(ServiceWithRepository::class)->repository,
        );
    }

    public function test_many_short_lived_scopes_do_not_accumulate(): void
    {
        $container = new Container();

        for ($i = 0; $i < 200; ++$i) {
            $container->createScope()->get(InMemoryRepository::class);
        }

        // Nothing here asserts a number; the point is that creating scopes in a
        // loop and dropping them stays bounded, and that configuration after
        // 200 of them still works.
        $container->when(ServiceWithRepository::class)
            ->needs(RepositoryInterface::class)
            ->give(InMemoryRepository::class);

        $scope = $container->createScope();

        self::assertInstanceOf(
            InMemoryRepository::class,
            $scope->get(ServiceWithRepository::class)->repository,
        );
    }
}
