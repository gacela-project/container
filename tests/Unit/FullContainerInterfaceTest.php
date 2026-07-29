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
use function in_array;
use function sort;

/**
 * The full surface of Container, reachable through an interface.
 *
 * Most of the 1.2–1.4 feature set used to be concrete-class-only, each method
 * documented as a limitation, which meant the library's own advice — depend on
 * the interface — cost a consumer exactly those features. This interface is additive:
 * ContainerInterface is untouched, so the 1.x promise that nothing is added to
 * it holds literally, and no existing implementor of it is affected.
 */
final class FullContainerInterfaceTest extends TestCase
{
    /**
     * Everything the interface exists to expose. Written out rather than
     * derived, so adding a method to Container without deciding whether it
     * belongs on the contract is a failing test rather than a silent omission.
     *
     * dependencyGraph() arrived after the interface did, and adding it broke
     * ForwardingContainer's compilation until it grew a forwarder — which is
     * the enforcement this whole interface exists to buy.
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
        'writeCompiledFactories',
    ];

    public function test_the_container_is_reachable_through_the_full_interface(): void
    {
        $container = new Container();

        self::assertInstanceOf(FullContainerInterface::class, $container);
        self::assertInstanceOf(ContainerInterface::class, $container);
        self::assertInstanceOf(PsrContainerInterface::class, $container);
    }

    public function test_it_declares_exactly_the_methods_containerinterface_does_not(): void
    {
        $full = self::methodsOf(FullContainerInterface::class);
        $base = self::methodsOf(ContainerInterface::class);

        $added = array_diff($full, $base);
        sort($added);

        $expected = self::EXPECTED;
        sort($expected);

        self::assertSame($expected, $added);
    }

    public function test_containerinterface_gained_nothing(): void
    {
        // The whole reason this is additive: 1.x promises no method is added to
        // ContainerInterface, and a test doubling it must keep compiling.
        foreach (self::EXPECTED as $method) {
            self::assertNotContains(
                $method,
                self::methodsOf(ContainerInterface::class),
                "{$method} was added to ContainerInterface, which 1.x promises not to extend",
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
        self::assertCount(12, self::EXPECTED);
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
