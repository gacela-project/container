<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\ValidationIssue;
use Gacela\Container\ValidationProblem;
use Gacela\Container\ValidationReport;
use GacelaTest\Fake\AbstractService;
use GacelaTest\Fake\CircularA;
use GacelaTest\Fake\ClassWithDependencyWithoutDependencies;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\ConstructionCounter;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\PersonWithoutDefaultValues;
use GacelaTest\Fake\PersonWithoutParamType;
use GacelaTest\Fake\PropertyCycleA;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\ServiceWithInjectedMethod;
use GacelaTest\Fake\ServiceWithInjectedMethodTakingAScalar;
use GacelaTest\Fake\ServiceWithInjectedProperty;
use GacelaTest\Fake\ServiceWithPrivateInjectedMethod;
use GacelaTest\Fake\ServiceWithPromotedInject;
use GacelaTest\Fake\ServiceWithReadonlyInjectedProperty;
use GacelaTest\Fake\ServiceWithRepository;
use GacelaTest\Fake\ServiceWithScalarInjectedProperty;
use GacelaTest\Fake\ServiceWithStaticInjectedMethod;
use GacelaTest\Fake\ServiceWithUnboundInjectedMethod;
use GacelaTest\Fake\ServiceWithUnboundInjectedProperty;
use GacelaTest\Fake\ServiceWithUnionType;
use GacelaTest\Fake\ServiceWithUntypedInjectedProperty;
use PHPUnit\Framework\Attributes\TestWith;
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

    #[TestWith([ServiceWithUnboundInjectedProperty::class])]
    #[TestWith([ServiceWithUnboundInjectedMethod::class])]
    public function test_validation_walks_injected_members_and_their_dependencies(string $class): void
    {
        $report = (new Container())->validate([$class]);

        self::assertFalse($report->isValid());
        self::assertSame(ValidationProblem::MissingClass, $report->issuesFor(RepositoryInterface::class)[0]->problem);
        self::assertSame(ValidationProblem::UnresolvableParameter, $report->issuesFor(PersonWithoutDefaultValues::class)[0]->problem);
        self::assertContains($class, $report->issuesFor(PersonWithoutDefaultValues::class)[0]->chain);
    }

    public function test_validation_walks_property_cycles(): void
    {
        $report = (new Container())->validate([PropertyCycleA::class]);

        self::assertFalse($report->isValid());
        self::assertSame(ValidationProblem::DependencyCycle, $report->issuesFor(PropertyCycleA::class)[0]->problem);
    }

    #[TestWith([ServiceWithReadonlyInjectedProperty::class])]
    #[TestWith([ServiceWithPrivateInjectedMethod::class])]
    #[TestWith([ServiceWithStaticInjectedMethod::class])]
    #[TestWith([ServiceWithScalarInjectedProperty::class])]
    #[TestWith([ServiceWithUntypedInjectedProperty::class])]
    public function test_invalid_injection_members_are_reported(string $class): void
    {
        self::assertFalse((new Container())->validate([$class])->isValid());
    }

    public function test_injected_setter_parameters_need_values_without_invoking_the_setter(): void
    {
        $container = new Container();
        self::assertFalse($container->validate([ServiceWithInjectedMethodTakingAScalar::class])->isValid());
        $container->when(ServiceWithInjectedMethodTakingAScalar::class)->needs('$dsn')->give('test');
        self::assertTrue($container->validate([ServiceWithInjectedMethodTakingAScalar::class])->isValid());
        self::assertTrue($container->validate([ServiceWithInjectedProperty::class, ServiceWithInjectedMethod::class])->isValid());
    }

    #[TestWith(['instance', false])]
    #[TestWith(['factory', false])]
    #[TestWith(['singleton', false])]
    #[TestWith(['instance', true])]
    #[TestWith(['factory', true])]
    #[TestWith(['singleton', true])]
    public function test_validation_uses_root_and_nested_registrations_without_invoking_factories(string $registration, bool $scope): void
    {
        $container = new Container();
        $calls = 0;
        $factory = static function () use (&$calls): PersonWithoutDefaultValues {
            ++$calls;
            return new PersonWithoutDefaultValues('registered');
        };
        match ($registration) {
            'instance' => $container->set(PersonWithoutDefaultValues::class, new PersonWithoutDefaultValues('registered')),
            'factory' => $container->bind(PersonWithoutDefaultValues::class, $factory),
            'singleton' => $container->singleton(PersonWithoutDefaultValues::class, $factory),
        };
        $receiver = $scope ? $container->createScope() : $container;
        $root = new class(new PersonWithoutDefaultValues('unused')) {
            public function __construct(public PersonWithoutDefaultValues $person)
            {
            }
        };
        $report = $receiver->validate([PersonWithoutDefaultValues::class, $root::class]);

        self::assertTrue($report->isValid(), $report->render());
        self::assertSame(0, $calls);
        self::assertSame('registered', $receiver->get($root::class)->person->name);
    }

    public function test_validation_checks_class_string_targets_instead_of_original_abstracts(): void
    {
        $container = new Container();
        $container->bind(AbstractService::class, ClassWithoutDependencies::class);
        self::assertTrue($container->validate([AbstractService::class])->isValid());
        $container->bind(AbstractService::class, PersonWithoutDefaultValues::class);
        $report = $container->validate([AbstractService::class]);
        self::assertFalse($report->isValid());
        self::assertNotEmpty($report->issuesFor(PersonWithoutDefaultValues::class));
    }

    public function test_validation_follows_aliases_and_scope_overrides(): void
    {
        $container = new Container();
        $container->alias(AbstractService::class, PersonWithoutDefaultValues::class);
        $container->set(PersonWithoutDefaultValues::class, new PersonWithoutDefaultValues('parent'));
        $scope = $container->createScope();
        $scope->set(PersonWithoutDefaultValues::class, new PersonWithoutDefaultValues('scope'));
        self::assertTrue($scope->validate([AbstractService::class])->isValid());
        self::assertSame('scope', $scope->get(AbstractService::class)->name);
    }

    public function test_validation_reports_unresolved_singleton_constructors(): void
    {
        $container = new Container();
        $container->singleton(PersonWithoutDefaultValues::class);
        self::assertFalse($container->validate([PersonWithoutDefaultValues::class])->isValid());
    }

    public function test_validation_keeps_concrete_binding_target_constructor_semantics(): void
    {
        $container = new Container();
        $container->bind(AbstractService::class, PersonWithoutDefaultValues::class);
        $container->bind(PersonWithoutDefaultValues::class, static fn (): PersonWithoutDefaultValues => new PersonWithoutDefaultValues('factory'));
        self::assertFalse($container->validate([PersonWithoutDefaultValues::class, AbstractService::class])->isValid());
    }

    public function test_a_registered_factory_can_supply_a_concrete_targets_self_dependency(): void
    {
        $value = new class(null) {
            public function __construct(public ?self $dependency)
            {
            }
        };
        $container = new Container();
        $container->bind(AbstractService::class, $value::class);
        $container->bind($value::class, static fn (): object => $value);
        $report = $container->validate([AbstractService::class]);

        self::assertTrue($report->isValid(), $report->render());
        self::assertSame($value, $container->get(AbstractService::class)->dependency);
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

    // ---------------------------------------------------------------
    // Exact rendering. This is what a failing build prints, so the
    // counts, the pluralisation and the separators are the contract —
    // assertStringContainsString() cannot hold any of them in place.
    // ---------------------------------------------------------------

    public function test_a_clean_report_renders_the_count_and_nothing_else(): void
    {
        $report = new ValidationReport([], ['A', 'B']);

        self::assertSame('2 class(es) checked, no problems found.', $report->render());
    }

    public function test_a_clean_report_of_nothing_still_counts(): void
    {
        self::assertSame(
            '0 class(es) checked, no problems found.',
            (new ValidationReport([], []))->render(),
        );
    }

    public function test_a_failing_report_renders_both_counts_and_the_issues(): void
    {
        $report = new ValidationReport(
            [
                new ValidationIssue('App\\A', ValidationProblem::MissingClass, 'it does not exist'),
                new ValidationIssue('App\\B', ValidationProblem::DependencyCycle, 'it loops', ['App\\A', 'App\\B']),
            ],
            ['App\\A', 'App\\B', 'App\\C'],
        );

        self::assertSame(
            "3 class(es) checked, 2 problem(s):\n\n"
            . "[missing-class] App\\A: it does not exist\n"
            . "[dependency-cycle] App\\B: it loops\n"
            . '    via App\\A -> App\\B',
            $report->render(),
        );
    }

    public function test_an_issue_without_a_chain_renders_one_line(): void
    {
        $issue = new ValidationIssue('App\\A', ValidationProblem::NotInstantiable, 'it is abstract');

        self::assertSame('[not-instantiable] App\\A: it is abstract', $issue->describe());
    }

    public function test_an_issue_with_a_chain_appends_the_path(): void
    {
        $issue = new ValidationIssue(
            'App\\C',
            ValidationProblem::UnresolvableParameter,
            'nothing supplies $name',
            ['App\\A', 'App\\B', 'App\\C'],
        );

        self::assertSame(
            "[unresolvable-parameter] App\\C: nothing supplies \$name\n    via App\\A -> App\\B -> App\\C",
            $issue->describe(),
        );
    }
}
