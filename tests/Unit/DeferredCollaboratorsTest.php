<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use GacelaTest\Fake\ClassWithoutDependencies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * A container built two collaborators that most containers never touch, and
 * createScope() paid for them per scope — the operation whose whole point is
 * being cheap enough to run per request.
 */
final class DeferredCollaboratorsTest extends TestCase
{
    /**
     * @return list<array{string}>
     */
    public static function deferredCollaborators(): array
    {
        return [
            ['tagRegistry'],
            ['dependencyTreeAnalyzer'],
        ];
    }

    #[DataProvider('deferredCollaborators')]
    public function test_a_container_that_resolves_does_not_build_it(string $property): void
    {
        $container = new Container();
        $container->get(ClassWithoutDependencies::class);

        self::assertNull(self::collaborator($container, $property));
    }

    #[DataProvider('deferredCollaborators')]
    public function test_a_scope_does_not_build_it_either(string $property): void
    {
        $scope = (new Container())->createScope();

        self::assertNull(self::collaborator($scope, $property));
    }

    public function test_tagging_builds_the_tag_registry_and_still_works(): void
    {
        $container = new Container();
        $container->tag(ClassWithoutDependencies::class, 'things');

        self::assertNotNull(self::collaborator($container, 'tagRegistry'));
        self::assertInstanceOf(
            ClassWithoutDependencies::class,
            iterator_to_array($container->tagged('things'))[0],
        );
    }

    /**
     * Two calls must reach one registry: rebuilding it per call would drop
     * whatever was grouped before.
     */
    public function test_tags_accumulate_across_calls(): void
    {
        $container = new Container();
        $container->tag(ClassWithoutDependencies::class, 'things');
        $container->tag(DeferredThing::class, 'things');

        self::assertCount(2, iterator_to_array($container->tagged('things')));
    }

    public function test_the_dependency_tree_still_works(): void
    {
        $container = new Container();

        self::assertSame(
            [ClassWithoutDependencies::class],
            $container->getDependencyTree(DeferredThing::class),
        );
    }

    private static function collaborator(Container $container, string $property): mixed
    {
        return (new ReflectionClass($container))
            ->getProperty($property)
            ->getValue($container);
    }
}

final class DeferredThing
{
    public function __construct(
        public ClassWithoutDependencies $dependency,
    ) {
    }
}
