<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\DependencyNode;
use GacelaTest\Fake\CircularA;
use GacelaTest\Fake\CircularB;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\Graph\CycleLeft;
use GacelaTest\Fake\Graph\CycleRoot;
use GacelaTest\Fake\Graph\CycleShared;
use GacelaTest\Fake\Graph\Diamond;
use GacelaTest\Fake\Graph\Leaf;
use GacelaTest\Fake\Graph\Left;
use GacelaTest\Fake\Graph\Right;
use GacelaTest\Fake\Graph\Shared;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\ServiceWithRepository;
use PHPUnit\Framework\TestCase;

/**
 * dependencyGraph() keeps what getDependencyTree() flattens away.
 *
 * The flat list answers "what does this touch". These are the questions it
 * cannot: how deep a dependency sits, which constructor parameter asked for it,
 * that two parents pull in the same class, and where a cycle closes.
 */
final class DependencyGraphTest extends TestCase
{
    public function test_the_root_is_the_class_asked_about_and_satisfies_no_parameter(): void
    {
        $graph = (new Container())->dependencyGraph(ServiceWithRepository::class);

        self::assertSame(ServiceWithRepository::class, $graph->className);
        self::assertNull($graph->parameter);
        self::assertFalse($graph->repeated);
    }

    public function test_a_child_records_the_parameter_it_satisfies(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, DatabaseRepository::class);

        $graph = $container->dependencyGraph(ServiceWithRepository::class);

        self::assertCount(1, $graph->children);
        self::assertSame('repository', $graph->children[0]->parameter);
    }

    public function test_a_binding_is_resolved_so_the_concrete_shows_up(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, DatabaseRepository::class);

        $graph = $container->dependencyGraph(ServiceWithRepository::class);

        // The flat list already did this; the graph must not lose it.
        self::assertSame(DatabaseRepository::class, $graph->children[0]->className);
    }

    public function test_scalar_and_untyped_parameters_are_not_dependencies(): void
    {
        $graph = (new Container())->dependencyGraph(Person::class);

        // Person takes a string. A graph of what to build has nothing to say
        // about it.
        self::assertSame([], $graph->children);
        self::assertSame(0, $graph->depth());
    }

    public function test_a_class_without_a_constructor_is_a_leaf(): void
    {
        $graph = (new Container())->dependencyGraph(ClassWithoutDependencies::class);

        self::assertSame([], $graph->children);
        self::assertFalse($graph->hasCycle());
    }

    public function test_depth_is_the_thing_a_flat_list_cannot_tell_you(): void
    {
        $graph = (new Container())->dependencyGraph(Diamond::class);

        // Diamond → Left → Shared → Leaf
        self::assertSame(3, $graph->depth());
    }

    public function test_a_shared_dependency_appears_under_every_parent_that_asks(): void
    {
        $graph = (new Container())->dependencyGraph(Diamond::class);

        self::assertCount(2, $graph->children);
        self::assertSame(Left::class, $graph->children[0]->className);
        self::assertSame(Right::class, $graph->children[1]->className);

        // The point: both branches reach Shared, and the graph says so twice.
        // Deduplication is what makes the flat list unable to.
        self::assertSame(Shared::class, $graph->children[0]->children[0]->className);
        self::assertSame(Shared::class, $graph->children[1]->children[0]->className);
        self::assertFalse($graph->children[1]->children[0]->repeated);
    }

    public function test_a_cycle_is_marked_and_cut_rather_than_thrown(): void
    {
        $graph = (new Container())->dependencyGraph(CircularA::class);

        $b = $graph->children[0];
        self::assertSame(CircularB::class, $b->className);

        $backToA = $b->children[0];
        self::assertSame(CircularA::class, $backToA->className);

        // Resolution throws on this. Inspection must not: a broken graph is
        // exactly when you reach for the inspector.
        self::assertTrue($backToA->repeated);
        self::assertSame([], $backToA->children);
        self::assertTrue($graph->hasCycle());
    }

    public function test_a_graph_without_a_cycle_says_so(): void
    {
        self::assertFalse((new Container())->dependencyGraph(Diamond::class)->hasCycle());
    }

    public function test_the_flat_list_is_derived_from_the_graph(): void
    {
        $container = new Container();

        // One traversal, two shapes: the list is the graph deduplicated, so
        // they cannot drift apart.
        self::assertSame(
            $container->getDependencyTree(Diamond::class),
            $container->dependencyGraph(Diamond::class)->flatten(),
        );
    }

    public function test_the_flat_list_still_deduplicates_and_excludes_the_root(): void
    {
        $flat = (new Container())->getDependencyTree(Diamond::class);

        self::assertSame([Left::class, Shared::class, Leaf::class, Right::class], $flat);
        self::assertNotContains(Diamond::class, $flat);
    }

    public function test_the_flat_list_terminates_on_a_cycle(): void
    {
        $container = new Container();

        self::assertSame([CircularB::class, CircularA::class], $container->getDependencyTree(CircularA::class));
    }

    public function test_a_subtree_that_differs_by_path_is_not_reused(): void
    {
        $graph = (new Container())->dependencyGraph(CycleRoot::class);

        // Via CycleLeft: Shared's child is an ancestor, so it is cut at once.
        $viaLeft = $graph->children[0]->children[0];
        self::assertSame(CycleShared::class, $viaLeft->className);
        self::assertTrue($viaLeft->children[0]->repeated);

        // Via CycleRight: CycleLeft is not an ancestor here, so the same class
        // expands one level further before cutting somewhere else. Reusing the
        // subtree built above would report this branch wrongly — which is why
        // only cycle-free subtrees may be shared between parents.
        $viaRight = $graph->children[1]->children[0];
        self::assertSame(CycleShared::class, $viaRight->className);
        self::assertFalse($viaRight->children[0]->repeated);
        self::assertSame(CycleLeft::class, $viaRight->children[0]->className);
        self::assertTrue($viaRight->children[0]->children[0]->repeated);
    }

    public function test_a_widely_shared_graph_is_built_once_per_class(): void
    {
        $container = new Container();

        // A cycle-free subtree cannot depend on the path taken to it, so both
        // parents get the same nodes rather than two builds of the same shape.
        $graph = $container->dependencyGraph(Diamond::class);

        self::assertSame(
            $graph->children[0]->children[0]->children,
            $graph->children[1]->children[0]->children,
        );
    }

    public function test_an_unknown_class_yields_an_empty_flat_list(): void
    {
        /** @var class-string $missing */
        $missing = 'GacelaTest\Fake\NoSuchClassAnywhere';

        self::assertSame([], (new Container())->getDependencyTree($missing));
    }

    public function test_render_draws_the_tree(): void
    {
        $expected = <<<TREE
            GacelaTest\Fake\Graph\Diamond
            ├── \$left: GacelaTest\Fake\Graph\Left
            │   └── \$shared: GacelaTest\Fake\Graph\Shared
            │       └── \$leaf: GacelaTest\Fake\Graph\Leaf
            └── \$right: GacelaTest\Fake\Graph\Right
                └── \$shared: GacelaTest\Fake\Graph\Shared
                    └── \$leaf: GacelaTest\Fake\Graph\Leaf
            TREE;

        self::assertSame($expected, (new Container())->dependencyGraph(Diamond::class)->render());
    }

    public function test_render_marks_a_cut_cycle(): void
    {
        $rendered = (new Container())->dependencyGraph(CircularA::class)->render();

        // A tree that simply stopped would look like one with nothing more to
        // say, which is the opposite of what a cycle means.
        self::assertStringContainsString('GacelaTest\Fake\CircularA (cycle)', $rendered);
    }

    public function test_it_casts_to_the_rendered_tree(): void
    {
        $graph = (new Container())->dependencyGraph(Diamond::class);

        self::assertSame($graph->render(), (string)$graph);
    }

    public function test_a_scope_sees_its_own_bindings_in_the_graph(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, DatabaseRepository::class);

        $scope = $container->createScope();
        $graph = $scope->dependencyGraph(ServiceWithRepository::class);

        self::assertSame(DatabaseRepository::class, $graph->children[0]->className);
    }

    public function test_a_node_can_be_built_directly(): void
    {
        // It is @api, so its constructor is part of the contract.
        $node = new DependencyNode(Leaf::class);

        self::assertSame(Leaf::class, $node->className);
        self::assertNull($node->parameter);
        self::assertSame([], $node->children);
        self::assertFalse($node->repeated);
        self::assertSame(Leaf::class, $node->render());
    }
}
