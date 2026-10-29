<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Attribute\Inject;
use Gacela\Container\Container;
use PHPUnit\Framework\TestCase;

interface LoggerInterface
{
    public function log(string $message): void;
}

final class FileLogger implements LoggerInterface
{
    public function log(string $message): void
    {
    }
}

final class DatabaseLogger implements LoggerInterface
{
    public function log(string $message): void
    {
    }
}

final class UserController
{
    public function __construct(public LoggerInterface $logger)
    {
    }
}

final class AdminController
{
    public function __construct(public LoggerInterface $logger)
    {
    }
}

final class NestedLoggerService
{
    public function __construct(public UserController $consumer, public LoggerInterface $logger)
    {
    }
}

class InjectedLoggerConsumer
{
    #[Inject]
    public LoggerInterface $propertyLogger;

    public LoggerInterface $setterLogger;

    #[Inject]
    public function setLogger(LoggerInterface $logger): void
    {
        $this->setterLogger = $logger;
    }
}

final class ChildInjectedLoggerConsumer extends InjectedLoggerConsumer
{
}

final class NestedInjectedLoggerService
{
    public function __construct(public ChildInjectedLoggerConsumer $consumer, public LoggerInterface $logger)
    {
    }
}

final class ContextualBindingTest extends TestCase
{
    protected function setUp(): void
    {
        Container::resetStaticCaches();
    }

    protected function tearDown(): void
    {
        Container::resetStaticCaches();
    }

    public function test_nested_consumer_uses_its_own_type_binding(): void
    {
        $container = new Container();
        $container->when(NestedLoggerService::class)
            ->needs(LoggerInterface::class)
            ->give(DatabaseLogger::class);
        $container->when(UserController::class)
            ->needs(LoggerInterface::class)
            ->give(FileLogger::class);

        for ($i = 0; $i < 2; ++$i) {
            $service = $container->get(NestedLoggerService::class);

            self::assertInstanceOf(FileLogger::class, $service->consumer->logger);
            self::assertInstanceOf(DatabaseLogger::class, $service->logger);
        }
    }

    public function test_root_type_binding_does_not_leak_to_nested_constructor(): void
    {
        $container = new Container([LoggerInterface::class => FileLogger::class]);
        $container->when(NestedLoggerService::class)
            ->needs(LoggerInterface::class)
            ->give(DatabaseLogger::class);

        for ($i = 0; $i < 2; ++$i) {
            $service = $container->get(NestedLoggerService::class);

            self::assertInstanceOf(FileLogger::class, $service->consumer->logger);
            self::assertInstanceOf(DatabaseLogger::class, $service->logger);
        }
    }

    public function test_type_binding_applies_to_injected_properties_and_setters(): void
    {
        $container = new Container([LoggerInterface::class => DatabaseLogger::class]);
        $container->when(InjectedLoggerConsumer::class)
            ->needs(LoggerInterface::class)
            ->give(FileLogger::class);

        for ($i = 0; $i < 2; ++$i) {
            $consumer = $container->get(InjectedLoggerConsumer::class);

            self::assertInstanceOf(FileLogger::class, $consumer->propertyLogger);
            self::assertInstanceOf(FileLogger::class, $consumer->setterLogger);
        }
    }

    public function test_inherited_properties_and_setters_use_the_class_being_built(): void
    {
        $container = new Container([LoggerInterface::class => DatabaseLogger::class]);
        $container->when(InjectedLoggerConsumer::class)
            ->needs(LoggerInterface::class)
            ->give(DatabaseLogger::class);
        $container->when(ChildInjectedLoggerConsumer::class)
            ->needs(LoggerInterface::class)
            ->give(FileLogger::class);
        $container->when(NestedInjectedLoggerService::class)
            ->needs(LoggerInterface::class)
            ->give(DatabaseLogger::class);

        for ($i = 0; $i < 2; ++$i) {
            $consumer = $container->get(ChildInjectedLoggerConsumer::class);
            $service = $container->get(NestedInjectedLoggerService::class);

            self::assertInstanceOf(FileLogger::class, $consumer->propertyLogger);
            self::assertInstanceOf(FileLogger::class, $consumer->setterLogger);
            self::assertInstanceOf(FileLogger::class, $service->consumer->propertyLogger);
            self::assertInstanceOf(FileLogger::class, $service->consumer->setterLogger);
            self::assertInstanceOf(DatabaseLogger::class, $service->logger);
        }
    }

    public function test_root_type_binding_does_not_leak_to_nested_properties_or_setters(): void
    {
        $container = new Container([LoggerInterface::class => FileLogger::class]);
        $container->when(NestedInjectedLoggerService::class)
            ->needs(LoggerInterface::class)
            ->give(DatabaseLogger::class);

        for ($i = 0; $i < 2; ++$i) {
            $service = $container->get(NestedInjectedLoggerService::class);

            self::assertInstanceOf(FileLogger::class, $service->consumer->propertyLogger);
            self::assertInstanceOf(FileLogger::class, $service->consumer->setterLogger);
            self::assertInstanceOf(DatabaseLogger::class, $service->logger);
        }
    }

    public function test_scopes_inherit_and_override_nested_consumer_type_bindings(): void
    {
        $container = new Container([LoggerInterface::class => DatabaseLogger::class]);
        $container->when(NestedLoggerService::class)
            ->needs(LoggerInterface::class)
            ->give(FileLogger::class);
        $container->when(UserController::class)
            ->needs(LoggerInterface::class)
            ->give(FileLogger::class);
        $scope = $container->createScope();
        $scope->when(UserController::class)
            ->needs(LoggerInterface::class)
            ->give(DatabaseLogger::class);
        $inheritingScope = $container->createScope();

        for ($i = 0; $i < 2; ++$i) {
            $parentService = $container->get(NestedLoggerService::class);
            $scopedService = $scope->get(NestedLoggerService::class);
            $inheritedService = $inheritingScope->get(NestedLoggerService::class);

            self::assertInstanceOf(FileLogger::class, $parentService->consumer->logger);
            self::assertInstanceOf(DatabaseLogger::class, $scopedService->consumer->logger);
            self::assertInstanceOf(FileLogger::class, $inheritedService->consumer->logger);
            self::assertInstanceOf(FileLogger::class, $scopedService->logger);
        }
    }

    public function test_contextual_binding_for_single_class(): void
    {
        $container = new Container();

        $container->when(UserController::class)
            ->needs(LoggerInterface::class)
            ->give(FileLogger::class);

        $container->when(AdminController::class)
            ->needs(LoggerInterface::class)
            ->give(DatabaseLogger::class);

        $userController = $container->get(UserController::class);
        $adminController = $container->get(AdminController::class);

        self::assertInstanceOf(FileLogger::class, $userController->logger);
        self::assertInstanceOf(DatabaseLogger::class, $adminController->logger);
    }

    public function test_contextual_binding_with_instance(): void
    {
        $container = new Container();
        $customLogger = new FileLogger();

        $container->when(UserController::class)
            ->needs(LoggerInterface::class)
            ->give($customLogger);

        $userController = $container->get(UserController::class);

        self::assertSame($customLogger, $userController->logger);
    }

    public function test_contextual_binding_with_closure(): void
    {
        $container = new Container();
        $called = false;

        $container->when(UserController::class)
            ->needs(LoggerInterface::class)
            ->give(static function () use (&$called) {
                $called = true;

                return new FileLogger();
            });

        $userController = $container->get(UserController::class);

        self::assertTrue($called);
        self::assertInstanceOf(FileLogger::class, $userController->logger);
    }

    public function test_contextual_binding_for_multiple_classes(): void
    {
        $container = new Container();

        $container->when([UserController::class, AdminController::class])
            ->needs(LoggerInterface::class)
            ->give(FileLogger::class);

        $userController = $container->get(UserController::class);
        $adminController = $container->get(AdminController::class);

        self::assertInstanceOf(FileLogger::class, $userController->logger);
        self::assertInstanceOf(FileLogger::class, $adminController->logger);
    }

    public function test_falls_back_to_global_binding_when_no_contextual_binding(): void
    {
        $container = new Container([
            LoggerInterface::class => DatabaseLogger::class,
        ]);

        $userController = $container->get(UserController::class);

        self::assertInstanceOf(DatabaseLogger::class, $userController->logger);
    }

    public function test_contextual_binding_overrides_global_binding(): void
    {
        $container = new Container([
            LoggerInterface::class => DatabaseLogger::class,
        ]);

        $container->when(UserController::class)
            ->needs(LoggerInterface::class)
            ->give(FileLogger::class);

        $userController = $container->get(UserController::class);
        $adminController = $container->get(AdminController::class);

        self::assertInstanceOf(FileLogger::class, $userController->logger);
        self::assertInstanceOf(DatabaseLogger::class, $adminController->logger);
    }
}
