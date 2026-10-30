<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Countable;
use Gacela\Container\Attribute\Inject;
use Gacela\Container\CompilationSkipReason;
use Gacela\Container\Container;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\InMemoryRepository;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\ServiceWithScalarVariadicParameter;
use GacelaTest\Fake\ServiceWithUntypedVariadicParameter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VariadicParameterTest extends TestCase
{
    protected function setUp(): void
    {
        Container::resetStaticCaches();
    }

    protected function tearDown(): void
    {
        Container::resetStaticCaches();
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function optionalServices(): iterable
    {
        yield 'scalar' => [ServiceWithScalarVariadicParameter::class];
        yield 'untyped' => [ServiceWithUntypedVariadicParameter::class];
        yield 'class' => [VariadicClassService::class];
        yield 'interface' => [VariadicInterfaceService::class];
        yield 'unknown class' => [VariadicUnknownService::class];
        yield 'union' => [VariadicUnionService::class];
        yield 'intersection' => [VariadicIntersectionService::class];
        yield 'self reference' => [VariadicSelfService::class];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('optionalServices')]
    public function test_absent_variadics_take_no_arguments_on_repeated_direct_and_nested_reads(string $class): void
    {
        $container = new Container();
        $container->when(VariadicOuterService::class)->needs('$service')->give(static fn () => $container->get($class));

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame([], $container->get($class)->values);
            self::assertSame([], $container->get(VariadicOuterService::class)->service->values);
        }
    }

    public function test_nested_class_variadics_do_not_build_an_ordinary_autowirable_dependency(): void
    {
        $container = new Container();

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame([], $container->get(VariadicTypedOuterService::class)->service->values);
        }
    }

    public function test_global_registrations_do_not_supply_an_implicit_variadic_element(): void
    {
        $container = new Container();
        $container->set(ClassWithoutDependencies::class, new ClassWithoutDependencies());
        $container->bind(RepositoryInterface::class, static fn (): never => self::fail('No variadic service should be resolved'));

        self::assertSame([], $container->get(VariadicClassService::class)->values);
        self::assertSame([], $container->get(VariadicInterfaceService::class)->values);
        self::assertFalse($container->isFrozen(ClassWithoutDependencies::class));
    }

    public function test_runtime_overrides_supply_one_value_and_other_overrides_leave_the_variadic_empty(): void
    {
        $container = new Container();

        self::assertSame(['one'], $container->make(ServiceWithScalarVariadicParameter::class, ['values' => 'one'])->values);
        self::assertSame([], $container->make(ServiceWithUntypedVariadicParameter::class, ['label' => 'chosen'])->values);
        self::assertSame('chosen', $container->make(ServiceWithUntypedVariadicParameter::class, ['label' => 'chosen'])->label);
        $value = new ClassWithoutDependencies();
        self::assertSame([$value], $container->make(VariadicClassService::class, ['values' => $value])->values);
        self::assertSame([['one', 'two']], $container->make(ServiceWithUntypedVariadicParameter::class, ['values' => ['one', 'two']])->values);
    }

    public function test_named_contextual_bindings_supply_one_value_including_null(): void
    {
        $container = new Container();
        $container->when(ServiceWithScalarVariadicParameter::class)->needs('$values')->give('one');
        $container->when(ServiceWithUntypedVariadicParameter::class)->needs('$values')->give(null);

        self::assertSame(['one'], $container->get(ServiceWithScalarVariadicParameter::class)->values);
        self::assertSame([null], $container->get(ServiceWithUntypedVariadicParameter::class)->values);
    }

    public function test_named_bindings_use_the_actual_consumer_and_invoke_a_closure_once(): void
    {
        $container = new Container();
        $calls = 0;
        $container->when(VariadicNamedParent::class)->needs('$values')->give('parent');
        $container->when(VariadicNamedChild::class)->needs('$values')->give(static function () use (&$calls): string {
            ++$calls;

            return 'child';
        });

        self::assertSame(['child'], $container->get(VariadicNamedChild::class)->values);
        self::assertSame(['parent'], $container->get(VariadicNamedParent::class)->values);
        self::assertSame(1, $calls);
    }

    public function test_a_required_parameter_before_a_variadic_keeps_its_position(): void
    {
        $container = new Container();
        $container->when(VariadicRequiredPrefixService::class)->needs('$label')->give('configured');

        for ($i = 0; $i < 3; ++$i) {
            $service = $container->get(VariadicRequiredPrefixService::class);
            self::assertSame('configured', $service->label);
            self::assertSame([], $service->values);
        }

        self::assertSame('override', $container->make(VariadicRequiredPrefixService::class, ['label' => 'override'])->label);
    }

    public function test_typed_contextual_bindings_and_parameter_inject_markers_supply_one_value(): void
    {
        $container = new Container();
        $value = new ClassWithoutDependencies();
        $container->when(VariadicClassService::class)->needs(ClassWithoutDependencies::class)->give($value);

        self::assertSame([$value], $container->get(VariadicClassService::class)->values);
        self::assertInstanceOf(InMemoryRepository::class, $container->get(VariadicInjectService::class)->values[0]);
    }

    public function test_callable_variadics_are_optional_and_an_explicit_override_remains_one_value(): void
    {
        $container = new Container();

        self::assertSame([], $container->resolve(static fn (string ...$values): array => $values));
        self::assertSame([], $container->resolve(static fn (RepositoryInterface ...$values): array => $values));
        self::assertSame(['one'], $container->resolve(static fn (string ...$values): array => $values, ['values' => 'one']));
    }

    public function test_injected_method_variadics_are_optional_and_honor_contextual_values(): void
    {
        $container = new Container();
        self::assertSame([], $container->get(VariadicSetterService::class)->values);
        $container->when(VariadicSetterService::class)->needs('$values')->give('one');
        self::assertSame(['one'], $container->get(VariadicSetterService::class)->values);
    }

    public function test_compiled_plans_and_generated_factory_fallback_keep_variadic_configuration_live(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'variadic-factories-');
        self::assertIsString($file);

        try {
            $generator = new Container();
            $classes = [VariadicTypedOuterService::class, VariadicClassService::class, ClassWithoutDependencies::class];
            $plans = $generator->compile($classes);
            $generator->writeCompiledFactories($classes, $file);
            $container = new Container([], [], $plans);
            $container->useCompiledFactories(Container::loadCompiledFactories($file));
            self::assertSame([], $container->get(VariadicTypedOuterService::class)->service->values);
            $value = new ClassWithoutDependencies();
            $container->when(VariadicClassService::class)->needs('$values')->give($value);
            self::assertSame([$value], $container->get(VariadicTypedOuterService::class)->service->values);
            self::assertSame(CompilationSkipReason::VariadicParameter, $generator->compileReport($classes)->reasonFor(VariadicClassService::class));
        } finally {
            unlink($file);
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('optionalServices')]
    public function test_validation_accepts_optional_variadics_without_resolving_their_types(string $class): void
    {
        self::assertTrue((new Container())->validate([$class])->isValid());
    }
}

final class VariadicClassService
{
    public array $values;

    public function __construct(ClassWithoutDependencies ...$values)
    {
        $this->values = $values;
    }
}

final class VariadicInterfaceService
{
    public array $values;

    public function __construct(RepositoryInterface ...$values)
    {
        $this->values = $values;
    }
}

final class VariadicUnknownService
{
    public array $values;

    public function __construct(MissingVariadicDependency ...$values)
    {
        $this->values = $values;
    }
}

final class VariadicUnionService
{
    public array $values;

    public function __construct(string|ClassWithoutDependencies ...$values)
    {
        $this->values = $values;
    }
}

final class VariadicOuterService
{
    public function __construct(public mixed $service)
    {
    }
}

final class VariadicTypedOuterService
{
    public function __construct(public VariadicClassService $service)
    {
    }
}

final class VariadicInjectService
{
    public array $values;

    public function __construct(#[Inject(InMemoryRepository::class)] RepositoryInterface ...$values)
    {
        $this->values = $values;
    }
}

final class VariadicSetterService
{
    public array $values = ['not called'];

    #[Inject]
    public function configure(string ...$values): void
    {
        $this->values = $values;
    }
}

final class VariadicIntersectionService
{
    public array $values;

    public function __construct(RepositoryInterface&Countable ...$values)
    {
        $this->values = $values;
    }
}

final class VariadicSelfService
{
    public array $values;

    public function __construct(self ...$values)
    {
        $this->values = $values;
    }
}

class VariadicNamedParent
{
    public array $values;

    public function __construct(string ...$values)
    {
        $this->values = $values;
    }
}

final class VariadicNamedChild extends VariadicNamedParent
{
}

final class VariadicRequiredPrefixService
{
    public array $values;

    public function __construct(public string $label, ClassWithoutDependencies ...$values)
    {
        $this->values = $values;
    }
}
