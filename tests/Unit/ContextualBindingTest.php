<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

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
        // File logging implementation
    }
}

final class DatabaseLogger implements LoggerInterface
{
    public function log(string $message): void
    {
        // Database logging implementation
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

final class ContextualBindingTest extends TestCase
{
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
