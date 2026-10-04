<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use GacelaTest\Fake\FactoryWithInjectedProperty;
use GacelaTest\Fake\Person;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * A compiled plan is the reflection the container would otherwise do on the
 * request that loads it. Every question a plan records an answer to is read
 * from the plan, so a loaded cache leaves no reflection on the resolution path.
 *
 * Proved by handing the container a plan that disagrees with the class: what
 * it does then can only have come from the plan.
 */
final class CompiledPlanAnswersReflectionTest extends TestCase
{
    protected function setUp(): void
    {
        Container::resetStaticCaches();
    }

    protected function tearDown(): void
    {
        Container::resetStaticCaches();
    }

    public function test_a_plan_records_the_lifetime_and_lazy_attributes(): void
    {
        $plans = (new Container())->compile([Person::class]);

        self::assertFalse($plans[Person::class]['singleton'] ?? null);
        self::assertFalse($plans[Person::class]['factory'] ?? null);
        self::assertFalse($plans[Person::class]['lazy'] ?? null);
    }

    public function test_the_singleton_flag_is_read_from_the_plan(): void
    {
        $plans = (new Container())->compile([Person::class]);
        Container::resetStaticCaches();
        $plans[Person::class]['singleton'] = true;

        $container = new Container([], [], $plans);

        self::assertSame($container->get(Person::class), $container->get(Person::class));
    }

    public function test_the_injected_properties_are_read_from_the_plan(): void
    {
        $plans = (new Container())->compile([FactoryWithInjectedProperty::class]);
        Container::resetStaticCaches();
        $plans[FactoryWithInjectedProperty::class]['props'] = [];

        $service = (new Container([], [], $plans))->get(FactoryWithInjectedProperty::class);

        self::assertInstanceOf(FactoryWithInjectedProperty::class, $service);
        self::assertFalse((new ReflectionProperty($service, 'dependency'))->isInitialized($service));
    }

    public function test_the_factory_flag_is_read_from_the_plan(): void
    {
        $plans = (new Container())->compile([FactoryWithInjectedProperty::class]);
        Container::resetStaticCaches();
        $plans[FactoryWithInjectedProperty::class]['factory'] = false;
        $plans[FactoryWithInjectedProperty::class]['singleton'] = true;

        $container = new Container([], [], $plans);

        self::assertSame($container->get(FactoryWithInjectedProperty::class), $container->get(FactoryWithInjectedProperty::class));
    }
}
