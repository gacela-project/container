<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\Exception\DependencyNotFoundException;
use Gacela\Container\PlanCache;
use GacelaTest\Fake\ClassWithObjectDependencies;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\InMemoryRepository;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\ServiceWithRepository;
use PHPUnit\Framework\TestCase;

/**
 * Sibling containers sharing one plan cache.
 *
 * The win is that the second container does not re-reflect what the first
 * already described. The constraint is that nothing else travels with it: a
 * plan records what a constructor asks for, never how a particular container
 * satisfied it, so two containers can share plans and still resolve
 * differently.
 */
final class SharedPlanCacheTest extends TestCase
{
    public function test_a_class_planned_by_one_container_is_planned_for_its_siblings(): void
    {
        $plans = new PlanCache();

        $first = new Container([], [], [], $plans);
        $second = new Container([], [], [], $plans);

        $first->get(ClassWithObjectDependencies::class);

        // The second container never touched either class, and already has
        // their plans: the reflection happened once for both.
        $planned = $second->compile([]);

        self::assertArrayHasKey(ClassWithObjectDependencies::class, $planned);
        self::assertArrayHasKey(Person::class, $planned);
    }

    public function test_a_cache_of_its_own_is_still_the_default(): void
    {
        $first = new Container();
        $second = new Container();

        $first->get(ClassWithObjectDependencies::class);

        self::assertSame([], $second->compile([]));
    }

    public function test_the_cache_reports_what_it_holds(): void
    {
        $plans = new PlanCache();
        self::assertSame(0, $plans->count());
        self::assertSame([], $plans->classes());

        (new Container([], [], [], $plans))->get(ClassWithoutDependencies::class);

        self::assertSame(1, $plans->count());
        self::assertSame([ClassWithoutDependencies::class], $plans->classes());
    }

    public function test_bindings_do_not_travel_with_the_plans(): void
    {
        $plans = new PlanCache();

        $bound = new Container([RepositoryInterface::class => InMemoryRepository::class], [], [], $plans);
        $unbound = new Container([], [], [], $plans);

        self::assertInstanceOf(InMemoryRepository::class, $bound->get(ServiceWithRepository::class)->repository);

        // Same plan for ServiceWithRepository in both. The plan says the
        // constructor needs a RepositoryInterface; only the binding says which
        // one, and that belongs to the container.
        $this->expectException(DependencyNotFoundException::class);

        $unbound->get(ServiceWithRepository::class);
    }

    public function test_contextual_bindings_do_not_travel_with_the_plans(): void
    {
        $plans = new PlanCache();

        $inMemory = new Container([], [], [], $plans);
        $database = new Container([], [], [], $plans);

        $inMemory->when(ServiceWithRepository::class)
            ->needs(RepositoryInterface::class)
            ->give(InMemoryRepository::class);

        $database->when(ServiceWithRepository::class)
            ->needs(RepositoryInterface::class)
            ->give(DatabaseRepository::class);

        self::assertInstanceOf(
            InMemoryRepository::class,
            $inMemory->get(ServiceWithRepository::class)->repository,
        );
        self::assertInstanceOf(
            DatabaseRepository::class,
            $database->get(ServiceWithRepository::class)->repository,
        );
    }

    public function test_instances_and_singletons_do_not_travel_with_the_plans(): void
    {
        $plans = new PlanCache();

        $first = new Container([], [], [], $plans);
        $second = new Container([], [], [], $plans);

        $first->singleton(ClassWithoutDependencies::class);
        $firstInstance = $first->get(ClassWithoutDependencies::class);
        $second->singleton(ClassWithoutDependencies::class);

        // Lifetime is what a container owns. Sharing plans must not make two
        // containers share what they built from them.
        self::assertNotSame($firstInstance, $second->get(ClassWithoutDependencies::class));
    }

    public function test_a_shared_cache_can_be_seeded_from_a_compiled_cache(): void
    {
        $compiled = (new Container())->compile([ClassWithObjectDependencies::class]);

        $plans = new PlanCache($compiled);
        $container = new Container([], [], [], $plans);

        self::assertContains(ClassWithObjectDependencies::class, $plans->classes());
        self::assertInstanceOf(
            ClassWithObjectDependencies::class,
            $container->get(ClassWithObjectDependencies::class),
        );
    }

    public function test_a_plan_already_in_the_shared_cache_wins_over_a_compiled_one(): void
    {
        $plans = new PlanCache();
        (new Container([], [], [], $plans))->get(Person::class);

        // A compiled plan came off disk and may describe a constructor that has
        // since changed; one already in the cache was reflected in this
        // process. The live one stays.
        $stale = [Person::class => ['instantiable' => false, 'params' => []]];
        $container = new Container([], [], $stale, $plans);

        self::assertInstanceOf(Person::class, $container->get(Person::class));
    }

    public function test_a_compiled_plan_seeds_a_class_the_shared_cache_does_not_have(): void
    {
        $compiled = (new Container())->compile([ClassWithoutDependencies::class]);

        $plans = new PlanCache();
        new Container([], [], $compiled, $plans);

        self::assertSame([ClassWithoutDependencies::class], $plans->classes());
    }

    public function test_a_scope_of_a_sharing_container_shares_the_same_cache(): void
    {
        $plans = new PlanCache();
        $container = new Container([], [], [], $plans);

        $container->createScope()->get(ClassWithoutDependencies::class);

        // A scope already shared its parent's plans; the parent's plans now
        // being shared sideways must not break that.
        self::assertSame([ClassWithoutDependencies::class], $plans->classes());
    }
}
