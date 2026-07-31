<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\ValidationIssue;
use Gacela\Container\ValidationProblem;
use GacelaTest\Fake\CircularA;
use GacelaTest\Fake\ClassWithDependencyWithoutDependencies;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\ConstructionCounter;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\PersonWithoutDefaultValues;
use GacelaTest\Fake\PersonWithoutParamType;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\ServiceWithPromotedInject;
use GacelaTest\Fake\ServiceWithRepository;
use GacelaTest\Fake\ServiceWithUnionType;
use PHPUnit\Framework\TestCase;

use function count;

final class ValidationTest extends TestCase
{
    protected function setUp(): void
    {
        Container::resetStaticCaches();
    }

    protected function tearDown(): void
    {
        Container::resetStaticCaches();
    }

    public function test_a_resolvable_graph_is_valid(): void
    {
        $report = (new Container())->validate([ClassWithDependencyWithoutDependencies::class]);

        self::assertTrue($report->isValid());
        self::assertSame([], $report->issues());
    }

    public function test_it_reports_every_class_it_reached(): void
    {
        $report = (new Container())->validate([ClassWithDependencyWithoutDependencies::class]);

        self::assertContains(ClassWithDependencyWithoutDependencies::class, $report->checked());
        self::assertContains(ClassWithoutDependencies::class, $report->checked());
    }

    /**
     * The whole point: this runs in a build, so it must not construct the
     * application it is checking.
     */
    public function test_validating_constructs_nothing(): void
    {
        ConstructionCounter::reset();

        (new Container())->validate([ClassWithDependencyWithoutDependencies::class]);

        self::assertSame(0, ConstructionCounter::countFor(ClassWithoutDependencies::class));
    }

    public function test_an_unbound_interface_dependency_is_reported(): void
    {
        $report = (new Container())->validate([ServiceWithRepository::class]);

        self::assertFalse($report->isValid());
        self::assertSame(
            ValidationProblem::MissingClass,
            $report->issuesFor(RepositoryInterface::class)[0]->problem,
        );
    }

    /**
     * `has()` is the oracle, so anything the container can satisfy is satisfied
     * here — without this validator knowing how bindings work.
     */
    public function test_a_bound_interface_dependency_is_valid(): void
    {
        $container = new Container([
            RepositoryInterface::class => DatabaseRepository::class,
        ]);

        self::assertTrue($container->validate([ServiceWithRepository::class])->isValid());
    }

    public function test_a_scalar_with_no_default_is_reported(): void
    {
        $report = (new Container())->validate([PersonWithoutDefaultValues::class]);

        self::assertFalse($report->isValid());

        $issue = $report->issuesFor(PersonWithoutDefaultValues::class)[0];
        self::assertSame(ValidationProblem::UnresolvableParameter, $issue->problem);
        self::assertStringContainsString('$name', $issue->explanation);
    }

    /**
     * The same parameter, supplied — so the report has to consult contextual
     * bindings or it would fail a container that resolves perfectly well.
     */
    public function test_a_scalar_supplied_by_a_contextual_binding_is_valid(): void
    {
        $container = new Container();
        $container->when(PersonWithoutDefaultValues::class)->needs('$name')->give('Frodo');

        self::assertTrue($container->validate([PersonWithoutDefaultValues::class])->isValid());
    }

    public function test_an_untyped_parameter_is_reported(): void
    {
        $report = (new Container())->validate([PersonWithoutParamType::class]);

        self::assertSame(
            ValidationProblem::UnresolvableParameter,
            $report->issuesFor(PersonWithoutParamType::class)[0]->problem,
        );
    }

    public function test_a_scalar_with_a_default_is_valid(): void
    {
        self::assertTrue((new Container())->validate([ServiceWithUnionType::class])->isValid());
    }

    public function test_a_cycle_is_reported_rather_than_thrown(): void
    {
        $report = (new Container())->validate([CircularA::class]);

        self::assertFalse($report->isValid());

        $problems = array_map(
            static fn ($issue) => $issue->problem,
            $report->issues(),
        );
        self::assertContains(ValidationProblem::DependencyCycle, $problems);
    }

    public function test_an_issue_names_the_path_that_reached_it(): void
    {
        $report = (new Container())->validate([ServiceWithRepository::class]);

        $issue = $report->issuesFor(RepositoryInterface::class)[0];

        self::assertContains(ServiceWithRepository::class, $issue->chain);
        self::assertStringContainsString('via', $issue->describe());
    }

    public function test_render_says_so_when_everything_is_fine(): void
    {
        $report = (new Container())->validate([ClassWithoutDependencies::class]);

        self::assertStringContainsString('no problems found', $report->render());
    }

    public function test_render_lists_the_problems(): void
    {
        $report = (new Container())->validate([ServiceWithRepository::class]);

        self::assertStringContainsString('problem(s)', $report->render());
        self::assertStringContainsString(RepositoryInterface::class, $report->render());
    }

    public function test_count_matches_the_issues(): void
    {
        $report = (new Container())->validate([ServiceWithRepository::class]);

        self::assertSame(count($report->issues()), $report->count());
    }

    public function test_a_class_reached_twice_is_only_reported_once(): void
    {
        // Two roots sharing a dependency is a diamond, not a problem.
        $report = (new Container())->validate([
            ServiceWithRepository::class,
            ServiceWithRepository::class,
        ]);

        self::assertCount(1, $report->issuesFor(RepositoryInterface::class));
    }

    public function test_a_cycle_explanation_names_the_whole_cycle(): void
    {
        $report = (new Container())->validate([CircularA::class]);

        $cycle = null;
        foreach ($report->issues() as $issue) {
            if ($issue->problem === ValidationProblem::DependencyCycle) {
                $cycle = $issue;
            }
        }

        self::assertNotNull($cycle);
        self::assertStringContainsString('->', $cycle->explanation);
        self::assertStringContainsString(CircularA::class, $cycle->explanation);
    }

    /**
     * A cycle is reported and the walk stops there. Without the stop it would
     * recurse until the stack gave out, so "reported once" is the assertion.
     */
    public function test_a_cycle_is_reported_once_and_does_not_recurse(): void
    {
        $report = (new Container())->validate([CircularA::class]);

        $cycles = array_filter(
            $report->issues(),
            static fn ($issue): bool => $issue->problem === ValidationProblem::DependencyCycle,
        );

        self::assertCount(1, $cycles);
    }

    public function test_an_interface_and_a_missing_class_are_explained_differently(): void
    {
        $report = (new Container())->validate([ServiceWithRepository::class]);
        self::assertStringContainsString(
            'interface',
            $report->issuesFor(RepositoryInterface::class)[0]->explanation,
        );

        /** @var list<class-string> $absent */
        $absent = ['GacelaTest\Fake\NoSuchClassAnywhere'];
        $missing = (new Container())->validate($absent);

        self::assertStringContainsString(
            'could not be autoloaded',
            $missing->issuesFor('GacelaTest\Fake\NoSuchClassAnywhere')[0]->explanation,
        );
    }

    /**
     * #[Inject] names the implementation, so the parameter is satisfied and
     * must not also be judged on its own declared type.
     */
    public function test_an_inject_parameter_is_judged_on_what_it_names(): void
    {
        self::assertTrue(
            (new Container())->validate([ServiceWithPromotedInject::class])->isValid(),
        );
    }

    public function test_an_issue_without_a_chain_prints_no_via_line(): void
    {
        $issue = new ValidationIssue('App\Thing', ValidationProblem::MissingClass, 'gone');

        self::assertSame('[missing-class] App\Thing: gone', $issue->describe());
    }

    public function test_an_issue_with_a_chain_prints_it(): void
    {
        $issue = new ValidationIssue('App\Thing', ValidationProblem::MissingClass, 'gone', ['App\Root']);

        self::assertSame(
            "[missing-class] App\Thing: gone\n    via App\Root",
            $issue->describe(),
        );
    }

    public function test_an_empty_root_list_is_valid_and_checks_nothing(): void
    {
        $report = (new Container())->validate([]);

        self::assertTrue($report->isValid());
        self::assertSame([], $report->checked());
    }
}
