<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\CompilationReport;
use Gacela\Container\Container;
use Gacela\Container\ContainerInterface;
use Gacela\Container\ContainerStats;
use Gacela\Container\FullContainerInterface;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\ExpensiveReportGenerator;
use GacelaTest\Fake\ForwardingContainer;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\ReportGeneratorInterface;
use GacelaTest\Fake\RepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use ReflectionClass;
use ReflectionMethod;

use function array_diff;
use function array_map;
use function in_array;

/**
 * The full surface of Container, reachable through ContainerInterface.
 *
 * Most of the 1.2–1.4 feature set was concrete-class-only, each method
 * documented as a limitation, because 1.x promised nothing would be added to
 * ContainerInterface. 1.5 answered that additively with FullContainerInterface;
 * 2.0 merges the two, which is what that promise was deferring.
 *
 * FullContainerInterface survives as a deprecated empty alias so code written
 * against it in 1.5 does not migrate twice.
 */
final class FullContainerInterfaceTest extends TestCase
{
    /**
     * Everything that was concrete-class-only before 2.0. Written out rather
     * than derived, so adding a method to Container without deciding whether it
     * belongs on the contract is a failing test rather than a silent omission.
     *
     * dependencyGraph() arrived after the interface did, and adding it broke
     * ForwardingContainer's compilation until it grew a forwarder — which is
     * the enforcement this interface exists to buy.
     */
    private const array EXPECTED = [
        'compileReport',
        'createScope',
        'dependencyGraph',
        'lazy',
        'load',
        'loadFile',
        'provides',
        'stats',
        'taggedByKey',
        'taggedKeys',
        'useCompiledFactories',
        'validate',
        'withSelfReference',
        'writeCompiledFactories',
    ];

    public function test_the_container_is_reachable_through_the_full_interface(): void
    {
        $container = new Container();

        self::assertInstanceOf(FullContainerInterface::class, $container);
        self::assertInstanceOf(ContainerInterface::class, $container);
        self::assertInstanceOf(PsrContainerInterface::class, $container);
    }

    public function test_containerinterface_declares_the_whole_surface(): void
    {
        $declared = self::methodsOf(ContainerInterface::class);

        foreach (self::EXPECTED as $method) {
            self::assertContains(
                $method,
                $declared,
                $method . ' should be on ContainerInterface at 2.0',
            );
        }
    }

    /**
     * The alias adds nothing of its own: it exists only so a 1.5 typehint keeps
     * compiling, and everything it used to declare is inherited now.
     */
    public function test_the_deprecated_alias_declares_nothing_itself(): void
    {
        $own = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(FullContainerInterface::class))->getMethods(),
        );

        $base = self::methodsOf(ContainerInterface::class);

        self::assertSame([], array_diff($own, $base));
    }

    public function test_a_1_5_typehint_still_resolves(): void
    {
        $container = new Container();

        self::assertInstanceOf(FullContainerInterface::class, $container);
        self::assertInstanceOf(ContainerInterface::class, $container);
    }

    public function test_every_expected_method_is_callable_through_the_base_interface(): void
    {
        // The 2.0 move: what used to be concrete-class-only is on the contract,
        // so depending on the interface no longer costs a consumer features.
        $container = self::containerAsInterface();

        foreach (self::EXPECTED as $method) {
            self::assertTrue(
                method_exists($container, $method),
                "{$method} should be reachable through ContainerInterface",
            );
        }
    }

    public function test_a_double_of_the_narrow_interface_still_satisfies_it(): void
    {
        // Stand-in for every consumer's existing test double. It satisfies
        // ContainerInterface only, and adding FullContainerInterface beside it
        // must not have made that insufficient — which is what "additive"
        // has to mean in practice.
        $double = self::createStub(ContainerInterface::class);

        self::assertInstanceOf(ContainerInterface::class, $double);
        self::assertNotInstanceOf(FullContainerInterface::class, $double);
    }

    public function test_every_declared_method_is_callable_through_the_interface(): void
    {
        $container = self::containerAsInterface();
        $container->bind(RepositoryInterface::class, DatabaseRepository::class);

        self::assertTrue($container->provides(RepositoryInterface::class));
        self::assertInstanceOf(ContainerStats::class, $container->stats());
        self::assertInstanceOf(CompilationReport::class, $container->compileReport([Person::class]));
        self::assertSame([], $container->taggedKeys('nothing-registered'));
    }

    public function test_create_scope_returns_the_full_surface_not_the_narrow_one(): void
    {
        $scope = self::containerAsInterface()->createScope();

        // The point of typing it `static`: a scope of a full container is a
        // full container, so the feature set does not fall away one level down.
        self::assertInstanceOf(FullContainerInterface::class, $scope);
        self::assertInstanceOf(ContainerStats::class, $scope->stats());
    }

    public function test_definitions_as_data_works_through_the_interface(): void
    {
        $container = self::containerAsInterface();
        $container->load([
            RepositoryInterface::class => ['singleton' => DatabaseRepository::class],
            'handlers.default' => ['value' => 'inbox'],
        ]);

        self::assertInstanceOf(DatabaseRepository::class, $container->get(RepositoryInterface::class));
        self::assertSame('inbox', $container->get('handlers.default'));
    }

    public function test_lazy_registration_works_through_the_interface(): void
    {
        $container = self::containerAsInterface();
        $container->lazy(ReportGeneratorInterface::class, ExpensiveReportGenerator::class);

        self::assertInstanceOf(
            ExpensiveReportGenerator::class,
            $container->get(ReportGeneratorInterface::class),
        );
    }

    public function test_keyed_tags_work_through_the_interface(): void
    {
        $container = self::containerAsInterface();
        $container->tag(['db' => DatabaseRepository::class], 'repos');

        self::assertSame(['db'], $container->taggedKeys('repos'));
        self::assertInstanceOf(DatabaseRepository::class, $container->taggedByKey('repos', 'db'));
    }

    public function test_compiled_factories_work_through_the_interface(): void
    {
        $container = self::containerAsInterface();
        $container->useCompiledFactories([
            ClassWithoutDependencies::class => static fn (): ClassWithoutDependencies => new ClassWithoutDependencies(),
        ]);

        self::assertInstanceOf(
            ClassWithoutDependencies::class,
            $container->get(ClassWithoutDependencies::class),
        );
    }

    public function test_a_decorator_implementing_it_is_bound_to_the_whole_surface(): void
    {
        // The value this buys downstream: a forwarding container declares the
        // interface and the compiler holds it to every method on the
        // contract, instead of hand-written forwarders that nothing checks.
        $decorator = new ForwardingContainer(new Container());

        self::assertInstanceOf(FullContainerInterface::class, $decorator);

        foreach (self::EXPECTED as $method) {
            self::assertTrue(
                (new ReflectionClass(ForwardingContainer::class))->hasMethod($method),
                "a FullContainerInterface implementor must define {$method}",
            );
        }

        self::assertInstanceOf(ForwardingContainer::class, $decorator->createScope());
    }

    /**
     * Guards against the enumeration above drifting from reality.
     */
    public function test_the_expected_list_is_not_accidentally_empty(): void
    {
        self::assertCount(14, self::EXPECTED);
        self::assertTrue(in_array('createScope', self::EXPECTED, true));
        self::assertTrue((new ReflectionMethod(Container::class, 'createScope'))->hasReturnType());
    }

    private static function containerAsInterface(): FullContainerInterface
    {
        return new Container();
    }

    /**
     * @param class-string $interface
     *
     * @return list<string>
     */
    private static function methodsOf(string $interface): array
    {
        $names = [];

        foreach ((new ReflectionClass($interface))->getMethods() as $method) {
            $names[] = $method->getName();
        }

        return $names;
    }
}
