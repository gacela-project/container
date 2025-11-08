<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Attribute\Factory;
use Gacela\Container\Attribute\Inject;
use Gacela\Container\Attribute\Singleton;
use Gacela\Container\Container;
use PHPUnit\Framework\TestCase;

final class AttributeTest extends TestCase
{
    public function test_inject_attribute_resolves_specific_implementation(): void
    {
        $container = new Container([
            AttributeLoggerInterface::class => AttributeFileLogger::class,
        ]);

        $service = $container->get(ServiceWithInjectAttribute::class);

        self::assertInstanceOf(ServiceWithInjectAttribute::class, $service);
        self::assertInstanceOf(AttributeConsoleLogger::class, $service->logger);
    }

    public function test_singleton_attribute_returns_same_instance(): void
    {
        $container = new Container();

        $instance1 = $container->get(SingletonService::class);
        $instance2 = $container->get(SingletonService::class);

        self::assertSame($instance1, $instance2);
    }

    public function test_factory_attribute_returns_new_instance(): void
    {
        $container = new Container();

        $instance1 = $container->get(FactoryService::class);
        $instance2 = $container->get(FactoryService::class);

        self::assertNotSame($instance1, $instance2);
        self::assertInstanceOf(FactoryService::class, $instance1);
        self::assertInstanceOf(FactoryService::class, $instance2);
    }

    public function test_singleton_with_dependencies(): void
    {
        $container = new Container();

        $service1 = $container->get(SingletonWithDependencies::class);
        $service2 = $container->get(SingletonWithDependencies::class);

        self::assertSame($service1, $service2);
        self::assertInstanceOf(SimpleService::class, $service1->dependency);
    }

    public function test_factory_with_dependencies(): void
    {
        $container = new Container();

        $service1 = $container->get(FactoryWithDependencies::class);
        $service2 = $container->get(FactoryWithDependencies::class);

        self::assertNotSame($service1, $service2);
        self::assertInstanceOf(SimpleService::class, $service1->dependency);
        self::assertInstanceOf(SimpleService::class, $service2->dependency);
    }

    public function test_inject_attribute_without_implementation_uses_type_hint(): void
    {
        $container = new Container([
            AttributeLoggerInterface::class => AttributeFileLogger::class,
        ]);

        $service = $container->get(ServiceWithInjectNoImplementation::class);

        self::assertInstanceOf(ServiceWithInjectNoImplementation::class, $service);
        self::assertInstanceOf(AttributeFileLogger::class, $service->logger);
    }

    public function test_mixed_attributes_with_inject_singleton_and_factory(): void
    {
        $container = new Container([
            AttributeLoggerInterface::class => AttributeFileLogger::class,
        ]);

        // Singleton should return same instance
        $singleton1 = $container->get(SingletonWithDependencies::class);
        $singleton2 = $container->get(SingletonWithDependencies::class);
        self::assertSame($singleton1, $singleton2);

        // Factory should return different instances
        $factory1 = $container->get(FactoryWithDependencies::class);
        $factory2 = $container->get(FactoryWithDependencies::class);
        self::assertNotSame($factory1, $factory2);

        // Inject should use specific implementation
        $service = $container->get(ServiceWithInjectAttribute::class);
        self::assertInstanceOf(AttributeConsoleLogger::class, $service->logger);
    }
}

// Test interfaces and classes

interface AttributeLoggerInterface
{
}

final class AttributeFileLogger implements AttributeLoggerInterface
{
}

final class AttributeConsoleLogger implements AttributeLoggerInterface
{
}

final class ServiceWithInjectAttribute
{
    public function __construct(
        #[Inject(AttributeConsoleLogger::class)]
        public AttributeLoggerInterface $logger,
    ) {
    }
}

final class ServiceWithInjectNoImplementation
{
    public function __construct(
        #[Inject]
        public AttributeLoggerInterface $logger,
    ) {
    }
}

#[Singleton]
final class SingletonService
{
}

#[Factory]
final class FactoryService
{
}

final class SimpleService
{
}

#[Singleton]
final class SingletonWithDependencies
{
    public function __construct(
        public SimpleService $dependency,
    ) {
    }
}

#[Factory]
final class FactoryWithDependencies
{
    public function __construct(
        public SimpleService $dependency,
    ) {
    }
}
