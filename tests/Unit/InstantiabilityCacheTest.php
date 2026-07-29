<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\PlanCache;
use GacelaTest\Fake\AbstractService;
use GacelaTest\Fake\ClassWithObjectDependencies;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\ExpensiveReportGenerator;
use GacelaTest\Fake\ReportGeneratorInterface;
use GacelaTest\Fake\RepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * "Can this class be instantiated?" has one answer per class per process.
 *
 * It used to have three: a per-instance cache on Container that built its own
 * ReflectionClass, the resolver's answer off the class plan, and a static memo
 * guarding construct(). A cold has() on an unregistered class therefore
 * reflected a class the plan registry was about to describe anyway.
 *
 * The plan is the single source, so the observable is the plan cache: asking
 * the question leaves behind the plan the following get() needs, and asking it
 * about a class a sibling already planned reflects nothing at all.
 */
final class InstantiabilityCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::resetStaticCaches();
    }

    public function test_a_cold_has_answers_off_the_plan_it_leaves_behind(): void
    {
        $plans = new PlanCache();
        $container = new Container([], [], [], $plans);

        self::assertSame([], $plans->classes());
        self::assertTrue($container->has(ClassWithObjectDependencies::class));

        // The reflection has already happened, once, and produced a plan. A
        // separate ReflectionClass built only to answer has() would leave the
        // cache empty here and make get() reflect the same class again.
        self::assertContains(ClassWithObjectDependencies::class, $plans->classes());
    }

    public function test_a_sibling_reuses_the_plan_rather_than_reflecting_again(): void
    {
        $plans = new PlanCache();

        $first = new Container([], [], [], $plans);
        $second = new Container([], [], [], $plans);

        $first->has(ClassWithoutDependencies::class);
        $planned = $plans->count();

        self::assertTrue($second->has(ClassWithoutDependencies::class));

        // The per-instance cache this replaced was per-container, so sibling
        // containers each rebuilt it.
        self::assertSame($planned, $plans->count());
    }

    public function test_has_agrees_with_get_for_a_class_it_already_probed(): void
    {
        $container = new Container();

        self::assertTrue($container->has(ClassWithObjectDependencies::class));
        self::assertInstanceOf(
            ClassWithObjectDependencies::class,
            $container->get(ClassWithObjectDependencies::class),
        );
    }

    public function test_a_bound_id_is_answered_before_the_probe_is_reached(): void
    {
        $plans = new PlanCache();
        $container = new Container([RepositoryInterface::class => DatabaseRepository::class], [], [], $plans);

        self::assertTrue($container->has(RepositoryInterface::class));

        // has() probes instantiability only after the instance, binding and
        // parent checks. Routing the probe through the plan must not make a
        // bound abstract start reflecting.
        self::assertSame([], $plans->classes());
    }

    public function test_a_registered_instance_is_answered_before_the_probe_is_reached(): void
    {
        $plans = new PlanCache();
        $container = new Container([], [], [], $plans);
        $container->set(ClassWithoutDependencies::class, new ClassWithoutDependencies());

        self::assertTrue($container->has(ClassWithoutDependencies::class));
        self::assertSame([], $plans->classes());
    }

    public function test_a_parent_binding_is_answered_before_the_probe_is_reached(): void
    {
        $plans = new PlanCache();
        $container = new Container([], [], [], $plans);
        $container->bind(RepositoryInterface::class, DatabaseRepository::class);

        self::assertTrue($container->createScope()->has(RepositoryInterface::class));
        self::assertSame([], $plans->classes());
    }

    public function test_an_id_that_is_not_a_class_never_reaches_the_plan(): void
    {
        $plans = new PlanCache();
        $container = new Container([], [], [], $plans);

        self::assertFalse($container->has('not-a-class'));

        // The class_exists() guard stays in front of the probe, and its negative
        // is deliberately not memoized: class_exists() can start answering true
        // later in the same process.
        self::assertSame([], $plans->classes());
    }

    public function test_an_abstract_class_is_not_instantiable(): void
    {
        $container = new Container();

        self::assertFalse($container->has(AbstractService::class));
        self::assertFalse($container->has(AbstractService::class));
    }

    public function test_a_lazy_target_is_proven_buildable_through_the_same_plan(): void
    {
        $plans = new PlanCache();
        $container = new Container([], [], [], $plans);

        $container->lazy(ReportGeneratorInterface::class, ExpensiveReportGenerator::class);

        // lazy() asserts its target is concrete. That question is the same one,
        // so it warms the same plan instead of reflecting for itself.
        self::assertContains(ExpensiveReportGenerator::class, $plans->classes());
    }

    public function test_a_class_that_becomes_loadable_stops_being_reported_as_missing(): void
    {
        $container = new Container();
        $later = 'GacelaTest\Fake\DeclaredAfterTheFirstProbe';

        self::assertFalse($container->has($later));

        class_alias(ClassWithoutDependencies::class, $later);

        // docs/performance.md promises only positives are cached, so a class
        // that was not loadable when it was first asked about is never
        // remembered as missing. The per-container cache this replaced stored
        // the negative and kept answering false for the rest of the process.
        self::assertTrue($container->has($later));
        self::assertInstanceOf(ClassWithoutDependencies::class, $container->get($later));
    }

    public function test_the_answer_survives_a_static_reset_by_being_rebuilt(): void
    {
        $container = new Container();

        self::assertTrue($container->has(ClassWithoutDependencies::class));

        Container::resetStaticCaches();

        self::assertTrue($container->has(ClassWithoutDependencies::class));
        self::assertInstanceOf(
            ClassWithoutDependencies::class,
            $container->get(ClassWithoutDependencies::class),
        );
    }
}
