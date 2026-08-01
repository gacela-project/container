<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Attribute\Factory;
use Gacela\Container\Attribute\Inject;
use Gacela\Container\Attribute\Lazy;
use Gacela\Container\Attribute\Singleton;
use Gacela\Container\Container;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\ConstructionCounter;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\FactoryAttributeService;
use GacelaTest\Fake\ServiceWithInjectedProperty;
use GacelaTest\Fake\ServiceWithSubclassedInjectMethod;
use GacelaTest\Fake\ServiceWithSubclassedInjectParameter;
use GacelaTest\Fake\ServiceWithSubclassedInjectProperty;
use GacelaTest\Fake\SingletonAttributeService;
use GacelaTest\Fake\SubclassedFactoryService;
use GacelaTest\Fake\SubclassedLazyService;
use GacelaTest\Fake\SubclassedSingletonService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * An exact-FQN attribute read matches neither a subclass nor a class_alias(),
 * and the failure is silent — the dependency simply never arrives. Every read
 * passes ReflectionAttribute::IS_INSTANCEOF, so a consumer can re-present these
 * under its own namespace.
 */
final class SubclassedAttributeTest extends TestCase
{
    protected function setUp(): void
    {
        Container::resetStaticCaches();
        ConstructionCounter::reset();
    }

    protected function tearDown(): void
    {
        Container::resetStaticCaches();
    }

    public function test_the_attributes_are_not_final_so_they_can_be_subclassed(): void
    {
        foreach ([Inject::class, Lazy::class, Singleton::class, Factory::class] as $attribute) {
            self::assertFalse(
                (new ReflectionClass($attribute))->isFinal(),
                $attribute . ' must be subclassable',
            );
        }
    }

    public function test_a_subclassed_inject_on_a_parameter_is_honoured(): void
    {
        $service = (new Container())->get(ServiceWithSubclassedInjectParameter::class);

        self::assertInstanceOf(DatabaseRepository::class, $service->repository);
    }

    public function test_a_subclassed_inject_on_a_property_is_honoured(): void
    {
        $service = (new Container())->get(ServiceWithSubclassedInjectProperty::class);

        self::assertInstanceOf(ClassWithoutDependencies::class, $service->dependency);
    }

    public function test_a_subclassed_inject_on_a_method_is_honoured(): void
    {
        $service = (new Container())->get(ServiceWithSubclassedInjectMethod::class);

        self::assertInstanceOf(ClassWithoutDependencies::class, $service->dependency);
    }

    public function test_a_subclassed_singleton_is_honoured(): void
    {
        $container = new Container();

        self::assertSame(
            $container->get(SubclassedSingletonService::class),
            $container->get(SubclassedSingletonService::class),
        );
    }

    /**
     * Transient is already the default, so "two calls differ" would pass with
     * the attribute ignored entirely. The compiler refusing the class is what
     * proves it was actually seen.
     */
    public function test_a_subclassed_factory_is_honoured(): void
    {
        $container = new Container();

        self::assertNotSame(
            $container->get(SubclassedFactoryService::class),
            $container->get(SubclassedFactoryService::class),
        );
        self::assertFalse(
            $container->compileReport([SubclassedFactoryService::class])
                ->wasCompiled(SubclassedFactoryService::class),
        );
    }

    public function test_a_subclassed_lazy_is_honoured(): void
    {
        if (!method_exists(ReflectionClass::class, 'newLazyGhost')) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $service = (new Container())->get(SubclassedLazyService::class);

        self::assertInstanceOf(SubclassedLazyService::class, $service);
        self::assertSame(0, ConstructionCounter::countFor(SubclassedLazyService::class));
    }

    public function test_an_exact_attribute_still_behaves_as_before(): void
    {
        $container = new Container();

        self::assertInstanceOf(
            ClassWithoutDependencies::class,
            $container->get(ServiceWithInjectedProperty::class)->dependency(),
        );
        self::assertSame(
            $container->get(SingletonAttributeService::class),
            $container->get(SingletonAttributeService::class),
        );
        self::assertNotSame(
            $container->get(FactoryAttributeService::class),
            $container->get(FactoryAttributeService::class),
        );
    }

    /**
     * The verdict is memoized per class, so the subclass answer has to be
     * cached against the class that carries it — not against the attribute.
     */
    public function test_the_subclass_verdict_is_memoized_per_class(): void
    {
        $first = new Container();
        self::assertSame(
            $first->get(SubclassedSingletonService::class),
            $first->get(SubclassedSingletonService::class),
        );

        // A second container reads the memo rather than re-reflecting.
        $second = new Container();
        self::assertSame(
            $second->get(SubclassedSingletonService::class),
            $second->get(SubclassedSingletonService::class),
        );
    }

    public function test_the_compiler_refuses_a_subclassed_lifetime_attribute(): void
    {
        $report = (new Container())->compileReport([SubclassedSingletonService::class]);

        self::assertFalse($report->wasCompiled(SubclassedSingletonService::class));
    }
}
