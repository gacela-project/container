<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use GacelaTest\Fake\ClassWithDependencyWithoutDependencies;
use GacelaTest\Fake\ClassWithInnerObjectDependencies;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\ServiceWithRepository;
use PHPUnit\Framework\TestCase;

use function count;

/**
 * The compiled-plan path and the reflection path must be indistinguishable.
 *
 * A divergence here means a container that behaves differently in production
 * (compiled cache warm) than in tests (reflection), which is the worst possible
 * failure mode for this feature.
 */
final class CompiledPlanEquivalenceTest extends TestCase
{
    public function test_compiled_and_reflected_resolution_produce_equal_graphs(): void
    {
        $classes = [
            ClassWithoutDependencies::class,
            ClassWithDependencyWithoutDependencies::class,
            ClassWithInnerObjectDependencies::class,
        ];

        $plans = (new Container())->compile($classes);

        foreach ($classes as $class) {
            $reflected = (new Container())->get($class);
            $compiled = (new Container([], [], $plans))->get($class);

            self::assertEquals($reflected, $compiled, "Divergence resolving {$class}");
            self::assertInstanceOf($class, $compiled);
        }
    }

    public function test_compiled_plans_respect_bindings(): void
    {
        $bindings = [RepositoryInterface::class => DatabaseRepository::class];

        $plans = (new Container($bindings))->compile([ServiceWithRepository::class]);

        $service = (new Container($bindings, [], $plans))->get(ServiceWithRepository::class);

        self::assertInstanceOf(ServiceWithRepository::class, $service);
        self::assertInstanceOf(DatabaseRepository::class, $service->repository);
    }

    public function test_compiled_plans_cover_the_whole_dependency_chain(): void
    {
        $plans = (new Container())->compile([ClassWithInnerObjectDependencies::class]);

        // Not just the requested class: its transitive dependencies too.
        self::assertArrayHasKey(ClassWithInnerObjectDependencies::class, $plans);
        self::assertGreaterThan(1, count($plans));
    }

    public function test_compiled_resolution_stays_transient(): void
    {
        $plans = (new Container())->compile([ClassWithDependencyWithoutDependencies::class]);
        $container = new Container([], [], $plans);

        $first = $container->get(ClassWithDependencyWithoutDependencies::class);
        $second = $container->get(ClassWithDependencyWithoutDependencies::class);

        self::assertNotSame($first, $second);
        self::assertNotSame($first->classWithoutDependencies, $second->classWithoutDependencies);
    }

    public function test_an_empty_plan_set_falls_back_to_reflection(): void
    {
        $container = new Container([], [], []);

        self::assertInstanceOf(
            ClassWithInnerObjectDependencies::class,
            $container->get(ClassWithInnerObjectDependencies::class),
        );
    }
}
