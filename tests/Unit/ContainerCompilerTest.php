<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\ContainerCompiler;
use Gacela\Container\Exception\DependencyInvalidArgumentException;
use GacelaTest\Fake\AbstractService;
use GacelaTest\Fake\CircularA;
use GacelaTest\Fake\CircularB;
use GacelaTest\Fake\ClassWithDependencyWithoutDependencies;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\ExpensiveReportGenerator;
use GacelaTest\Fake\FactoryAttributeService;
use GacelaTest\Fake\LazyService;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\ServiceWithRepository;
use GacelaTest\Fake\ServiceWithScalarDependency;
use GacelaTest\Fake\SingletonAttributeService;
use PHPUnit\Framework\TestCase;

/**
 * The generator must never produce something that resolves differently from
 * the runtime path. Anything it is not certain about is left out, so these
 * tests are mostly about what it *refuses* to compile.
 */
final class ContainerCompilerTest extends TestCase
{
    public function test_it_compiles_a_class_with_no_dependencies(): void
    {
        $compiler = self::compilerFor([ClassWithoutDependencies::class]);

        self::assertContains(ClassWithoutDependencies::class, $compiler->compilable());
    }

    public function test_it_compiles_a_nested_object_graph(): void
    {
        $compiler = self::compilerFor([ClassWithDependencyWithoutDependencies::class]);

        self::assertContains(ClassWithDependencyWithoutDependencies::class, $compiler->compilable());
        self::assertStringContainsString(
            'new \\' . ClassWithDependencyWithoutDependencies::class . '(new \\' . ClassWithoutDependencies::class . '())',
            $compiler->render(),
        );
    }

    public function test_it_refuses_a_class_with_a_defaulted_scalar(): void
    {
        // Plannable, unlike an unresolvable scalar, so it reaches the
        // generator — which must still refuse it, because the value can come
        // from a contextual binding the generator does not model.
        $compiler = self::compilerFor([Person::class]);

        self::assertNotContains(Person::class, $compiler->compilable());
    }

    public function test_a_class_with_an_unresolvable_scalar_never_reaches_the_generator(): void
    {
        $this->expectException(DependencyInvalidArgumentException::class);

        self::compilerFor([ServiceWithScalarDependency::class]);
    }

    public function test_it_refuses_a_bound_abstract(): void
    {
        $bindings = [RepositoryInterface::class => DatabaseRepository::class];
        $compiler = self::compilerFor([ServiceWithRepository::class], $bindings);

        // A binding can be changed after compilation, so baking it in would be
        // a divergence waiting to happen.
        self::assertNotContains(ServiceWithRepository::class, $compiler->compilable());
    }

    public function test_it_refuses_attribute_driven_classes(): void
    {
        $compiler = self::compilerFor([
            SingletonAttributeService::class,
            FactoryAttributeService::class,
            LazyService::class,
        ]);

        $compilable = $compiler->compilable();

        self::assertNotContains(SingletonAttributeService::class, $compilable);
        self::assertNotContains(FactoryAttributeService::class, $compilable);
        self::assertNotContains(LazyService::class, $compilable);
    }

    public function test_it_refuses_a_class_registered_through_lazy(): void
    {
        $container = new Container();
        $container->lazy(ExpensiveReportGenerator::class);

        $file = tempnam(sys_get_temp_dir(), 'compiled') . '.php';
        $compiled = $container->writeCompiledFactories(
            [ExpensiveReportGenerator::class, ClassWithoutDependencies::class],
            $file,
        );
        @unlink($file);

        // There is no attribute to see here: the registration is the only thing
        // saying the class is lazy, and a `new` expression would bypass it.
        self::assertNotContains(ExpensiveReportGenerator::class, $compiled);
        self::assertContains(ClassWithoutDependencies::class, $compiled);
    }

    public function test_the_rendered_file_is_valid_php_returning_factories(): void
    {
        $compiler = self::compilerFor([ClassWithDependencyWithoutDependencies::class]);

        $file = tempnam(sys_get_temp_dir(), 'compiled') . '.php';
        file_put_contents($file, $compiler->render());

        /** @var array<class-string, callable> $factories */
        $factories = require $file;
        @unlink($file);

        self::assertArrayHasKey(ClassWithDependencyWithoutDependencies::class, $factories);
        self::assertInstanceOf(
            ClassWithDependencyWithoutDependencies::class,
            $factories[ClassWithDependencyWithoutDependencies::class](),
        );
    }

    public function test_generated_construction_matches_the_runtime_path(): void
    {
        $classes = [
            ClassWithoutDependencies::class,
            ClassWithDependencyWithoutDependencies::class,
        ];
        $compiler = self::compilerFor($classes);

        $file = tempnam(sys_get_temp_dir(), 'compiled') . '.php';
        file_put_contents($file, $compiler->render());
        /** @var array<class-string, callable> $factories */
        $factories = require $file;
        @unlink($file);

        $container = new Container();

        foreach ($compiler->compilable() as $class) {
            self::assertEquals(
                $container->get($class),
                $factories[$class](),
                "Generated construction diverges from runtime resolution for {$class}",
            );
        }
    }

    public function test_generated_factories_stay_transient(): void
    {
        $compiler = self::compilerFor([ClassWithDependencyWithoutDependencies::class]);

        $file = tempnam(sys_get_temp_dir(), 'compiled') . '.php';
        file_put_contents($file, $compiler->render());
        /** @var array<class-string, callable> $factories */
        $factories = require $file;
        @unlink($file);

        $factory = $factories[ClassWithDependencyWithoutDependencies::class];

        self::assertNotSame($factory(), $factory());
    }

    public function test_it_refuses_a_class_that_is_not_instantiable(): void
    {
        $compiler = new ContainerCompiler([
            AbstractService::class => ['instantiable' => false, 'params' => []],
        ]);

        self::assertSame([], $compiler->compilable());
    }

    public function test_it_refuses_a_class_with_no_plan(): void
    {
        // A dependency the planner never described cannot be generated.
        $compiler = new ContainerCompiler([
            ClassWithDependencyWithoutDependencies::class => [
                'instantiable' => true,
                'params' => [[
                    'name' => 'classWithoutDependencies',
                    'hasType' => true,
                    'type' => ClassWithoutDependencies::class,
                    'isScalar' => false,
                    'inject' => null,
                    'hasDefault' => false,
                    'default' => null,
                    'declaringClass' => ClassWithDependencyWithoutDependencies::class,
                ]],
            ],
        ]);

        self::assertSame([], $compiler->compilable());
    }

    public function test_it_refuses_an_inject_annotated_parameter(): void
    {
        $compiler = new ContainerCompiler([
            ClassWithDependencyWithoutDependencies::class => [
                'instantiable' => true,
                'params' => [[
                    'name' => 'classWithoutDependencies',
                    'hasType' => true,
                    'type' => ClassWithoutDependencies::class,
                    'isScalar' => false,
                    'inject' => ClassWithoutDependencies::class,
                    'hasDefault' => false,
                    'default' => null,
                    'declaringClass' => ClassWithDependencyWithoutDependencies::class,
                ]],
            ],
            ClassWithoutDependencies::class => ['instantiable' => true, 'params' => []],
        ]);

        self::assertNotContains(ClassWithDependencyWithoutDependencies::class, $compiler->compilable());
    }

    public function test_it_refuses_a_dependency_cycle(): void
    {
        $compiler = new ContainerCompiler([
            CircularA::class => [
                'instantiable' => true,
                'params' => [[
                    'name' => 'b',
                    'hasType' => true,
                    'type' => CircularB::class,
                    'isScalar' => false,
                    'inject' => null,
                    'hasDefault' => false,
                    'default' => null,
                    'declaringClass' => CircularA::class,
                ]],
            ],
            CircularB::class => [
                'instantiable' => true,
                'params' => [[
                    'name' => 'a',
                    'hasType' => true,
                    'type' => CircularA::class,
                    'isScalar' => false,
                    'inject' => null,
                    'hasDefault' => false,
                    'default' => null,
                    'declaringClass' => CircularB::class,
                ]],
            ],
        ]);

        self::assertSame([], $compiler->compilable());
    }

    public function test_rendering_is_deterministic(): void
    {
        $first = self::compilerFor([ClassWithDependencyWithoutDependencies::class])->render();
        $second = self::compilerFor([ClassWithDependencyWithoutDependencies::class])->render();

        self::assertSame($first, $second);
    }
    /**
     * @param list<class-string> $classes
     */
    private static function compilerFor(array $classes, array $bindings = []): ContainerCompiler
    {
        $plans = (new Container($bindings))->compile($classes);

        return new ContainerCompiler($plans, $bindings);
    }
}
