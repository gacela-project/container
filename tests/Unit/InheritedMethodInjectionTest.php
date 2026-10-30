<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Attribute\Inject;
use Gacela\Container\CompilationSkipReason;
use Gacela\Container\Container;
use Gacela\Container\DependencyResolver;
use Gacela\Container\Exception\DependencyInvalidArgumentException;
use GacelaTest\Fake\Attribute\AppInject;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\InMemoryRepository;
use GacelaTest\Fake\ParentDeclaringAnInjectedMethod;
use GacelaTest\Fake\RepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class InheritedMethodInjectionTest extends TestCase
{
    protected function setUp(): void
    {
        Container::resetStaticCaches();
        UnmarkedSetterService::$totalCalls = 0;
    }

    protected function tearDown(): void
    {
        Container::resetStaticCaches();
    }

    /**
     * @return iterable<string, array{class-string<UnmarkedSetterService>}>
     */
    public static function interfaceImplementations(): iterable
    {
        yield 'interface added to inherited implementation' => [InterfaceMarkedSetterService::class];
        yield 'interface diamond with redeclared method and attribute subclass' => [DiamondMarkedSetterService::class];
        yield 'unmarked prototype before marked interface' => [MultipleContractSetterService::class];
        yield 'markers on implementation and interface' => [RepeatedMarkedSetterService::class];
        yield 'marked middle parent with an older unmarked prototype' => [OverridingMiddleSetterService::class];
    }

    /**
     * @param class-string<UnmarkedSetterService> $class
     */
    #[DataProvider('interfaceImplementations')]
    public function test_inherited_markers_call_the_actual_implementation_once(string $class): void
    {
        $container = new Container();
        $dependency = new ClassWithoutDependencies();
        $container->when($class)->needs(ClassWithoutDependencies::class)->give($dependency);

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $service = $container->get($class);
            self::assertSame($dependency, $service->dependency);
            self::assertSame(1, $service->calls);
        }

        self::assertSame(3, UnmarkedSetterService::$totalCalls);
    }

    public function test_an_overridden_parent_marker_reaches_the_child_and_grandchild(): void
    {
        $container = new Container();
        foreach ([OverridingParentSetterService::class, GrandchildOverridingSetterService::class] as $class) {
            for ($attempt = 0; $attempt < 3; ++$attempt) {
                $service = $container->get($class);
                self::assertInstanceOf(ClassWithoutDependencies::class, $service->fromParent);
                self::assertSame(1, $service->calls);
                self::assertSame($class, $service->implementation);
            }
        }
    }

    public function test_only_the_implementation_parameters_supply_attributes_and_defaults(): void
    {
        $service = (new Container())->get(ImplementationParameterSetterService::class);

        self::assertInstanceOf(DatabaseRepository::class, $service->repository);
        self::assertSame('implementation default', $service->label);
        self::assertSame(1, $service->calls);
    }

    public function test_implementation_parameter_names_receive_contextual_values(): void
    {
        $container = new Container();
        $container->when(ImplementationParameterSetterService::class)->needs('$label')->give('configured');
        $service = $container->get(ImplementationParameterSetterService::class);

        self::assertInstanceOf(DatabaseRepository::class, $service->repository);
        self::assertSame('configured', $service->label);
        self::assertSame(1, $service->calls);
    }

    public function test_nested_resolution_and_cached_plans_keep_inherited_markers(): void
    {
        $container = new Container();
        $plans = $container->compile([InheritedSetterConsumer::class]);
        Container::resetStaticCaches();
        $cached = new Container([], [], $plans);

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $service = $cached->get(InheritedSetterConsumer::class)->service;
            self::assertInstanceOf(ClassWithoutDependencies::class, $service->dependency);
            self::assertSame(1, $service->calls);
        }
    }

    public function test_generated_factories_refuse_inherited_method_injection(): void
    {
        $report = (new Container())->compileReport([InterfaceMarkedSetterService::class, OverridingParentSetterService::class]);

        self::assertSame(CompilationSkipReason::InjectedMethod, $report->reasonFor(InterfaceMarkedSetterService::class));
        self::assertSame(CompilationSkipReason::InjectedMethod, $report->reasonFor(OverridingParentSetterService::class));
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function invalidMethods(): iterable
    {
        yield 'static interface implementation' => [StaticInterfaceSetterService::class];
        yield 'protected parent override' => [ProtectedOverrideSetterService::class];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('invalidMethods')]
    public function test_invalid_implementation_visibility_and_static_methods_still_fail(string $class): void
    {
        $this->expectException(DependencyInvalidArgumentException::class);
        (new Container())->get($class);
    }

    public function test_a_private_parent_method_does_not_mark_an_unrelated_child_method(): void
    {
        $service = (new Container())->get(UnrelatedPublicSetterService::class);

        self::assertSame(0, $service->calls);
    }

    public function test_an_interface_constructor_marker_does_not_call_the_constructor_twice(): void
    {
        $service = (new Container())->get(InterfaceConstructorService::class);

        self::assertSame(1, $service->calls);
        self::assertInstanceOf(ClassWithoutDependencies::class, $service->dependency);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function lazyModes(): iterable
    {
        yield 'native lazy' => [false];
        yield 'eager fallback' => [true];
    }

    #[DataProvider('lazyModes')]
    public function test_lazy_initialization_keeps_inherited_method_injection(bool $forceEager): void
    {
        if (!$forceEager && !method_exists(ReflectionClass::class, 'newLazyGhost')) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        if ($forceEager) {
            (new ReflectionProperty(DependencyResolver::class, 'lazyObjectsAvailable'))->setValue(null, false);
        }

        $container = new Container();
        $container->lazy(InterfaceMarkedSetterService::class);
        $service = $container->get(InterfaceMarkedSetterService::class);

        self::assertSame($forceEager ? 1 : 0, UnmarkedSetterService::$totalCalls);
        self::assertInstanceOf(ClassWithoutDependencies::class, $service->dependency);
        self::assertSame(1, $service->calls);
        self::assertSame(1, UnmarkedSetterService::$totalCalls);
    }
}

interface SetterInjectionContract
{
    #[Inject]
    public function setDependency(ClassWithoutDependencies $contractDependency): void;
}

interface RootSetterInjectionContract
{
    #[AppInject]
    public function setDependency(ClassWithoutDependencies $contractDependency): void;
}

interface LeftSetterInjectionContract extends RootSetterInjectionContract
{
}

interface RightSetterInjectionContract extends RootSetterInjectionContract
{
    public function setDependency(ClassWithoutDependencies $redeclaredDependency): void;
}

interface DiamondSetterInjectionContract extends LeftSetterInjectionContract, RightSetterInjectionContract
{
}

interface UnmarkedSetterContract
{
    public function setDependency(ClassWithoutDependencies $contractDependency): void;
}

class UnmarkedSetterService
{
    public static int $totalCalls = 0;

    public ?ClassWithoutDependencies $dependency = null;

    public int $calls = 0;

    public function setDependency(ClassWithoutDependencies $implementationDependency): void
    {
        $this->dependency = $implementationDependency;
        ++$this->calls;
        ++self::$totalCalls;
    }
}

class InterfaceMarkedSetterService extends UnmarkedSetterService implements SetterInjectionContract
{
}

class DiamondMarkedSetterService extends UnmarkedSetterService implements DiamondSetterInjectionContract
{
}

final class MultipleContractSetterService extends UnmarkedSetterService implements UnmarkedSetterContract, RootSetterInjectionContract
{
}

final class RepeatedMarkedSetterService extends DiamondMarkedSetterService
{
    #[Inject]
    public function setDependency(ClassWithoutDependencies $implementationDependency): void
    {
        parent::setDependency($implementationDependency);
    }
}

class MarkedMiddleSetterService extends UnmarkedSetterService
{
    #[Inject]
    public function setDependency(ClassWithoutDependencies $implementationDependency): void
    {
        parent::setDependency($implementationDependency);
    }
}

final class OverridingMiddleSetterService extends MarkedMiddleSetterService
{
    public function setDependency(ClassWithoutDependencies $implementationDependency): void
    {
        parent::setDependency($implementationDependency);
    }
}

class OverridingParentSetterService extends ParentDeclaringAnInjectedMethod
{
    public int $calls = 0;

    public string $implementation = '';

    public function setFromParent(ClassWithoutDependencies $renamedDependency): void
    {
        $this->fromParent = $renamedDependency;
        ++$this->calls;
        $this->implementation = self::class;
    }
}

final class GrandchildOverridingSetterService extends OverridingParentSetterService
{
    public function setFromParent(ClassWithoutDependencies $renamedAgain): void
    {
        $this->fromParent = $renamedAgain;
        ++$this->calls;
        $this->implementation = self::class;
    }
}

interface RepositorySetterInjectionContract
{
    #[Inject]
    public function setRepository(#[Inject(InMemoryRepository::class)] RepositoryInterface $contractRepository): void;
}

final class ImplementationParameterSetterService implements RepositorySetterInjectionContract
{
    public ?RepositoryInterface $repository = null;

    public string $label = '';

    public int $calls = 0;

    public function setRepository(
        #[Inject(DatabaseRepository::class)] RepositoryInterface $implementationRepository,
        string $label = 'implementation default',
    ): void {
        $this->repository = $implementationRepository;
        $this->label = $label;
        ++$this->calls;
    }
}

final class InheritedSetterConsumer
{
    public function __construct(public InterfaceMarkedSetterService $service)
    {
    }
}

interface StaticSetterInjectionContract
{
    #[Inject]
    public static function setDependency(ClassWithoutDependencies $dependency): void;
}

final class StaticInterfaceSetterService implements StaticSetterInjectionContract
{
    public static function setDependency(ClassWithoutDependencies $dependency): void
    {
    }
}

class ProtectedMarkedSetterService
{
    #[Inject]
    protected function setDependency(ClassWithoutDependencies $dependency): void
    {
    }
}

final class ProtectedOverrideSetterService extends ProtectedMarkedSetterService
{
    protected function setDependency(ClassWithoutDependencies $dependency): void
    {
    }
}

class PrivateMarkedSetterService
{
    #[Inject]
    private function setDependency(ClassWithoutDependencies $dependency): void
    {
    }
}

final class UnrelatedPublicSetterService extends PrivateMarkedSetterService
{
    public int $calls = 0;

    public function setDependency(ClassWithoutDependencies $dependency): void
    {
        ++$this->calls;
    }
}

interface ConstructorInjectionContract
{
    #[Inject]
    public function __construct(ClassWithoutDependencies $dependency);
}

final class InterfaceConstructorService implements ConstructorInjectionContract
{
    public int $calls = 0;

    public function __construct(public ClassWithoutDependencies $dependency)
    {
        ++$this->calls;
    }
}
