<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\DependencyResolver;
use Gacela\Container\Exception\ContainerException;
use GacelaTest\Fake\CompilableDependency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use TypeError;

final class LazyFactoryCompatibilityTest extends TestCase
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
     * @return iterable<string, array{class-string<LazyFactoryLayoutBase>, string}>
     */
    public static function incompatibleSubtypes(): iterable
    {
        foreach ([LazyFactorySameLayoutChild::class, LazyFactoryExtraPropertyChild::class] as $resultClass) {
            foreach (['direct', 'alias', 'nested', 'scope'] as $context) {
                yield $resultClass . ' ' . $context => [$resultClass, $context];
            }
        }
    }

    /**
     * @param class-string<LazyFactoryLayoutBase> $resultClass
     */
    #[DataProvider('incompatibleSubtypes')]
    public function test_incompatible_subtypes_report_the_registration_on_first_use(string $resultClass, string $context): void
    {
        self::requireNativeLazyObjects();
        $calls = 0;
        $container = new Container();
        $container->lazy(LazyFactoryLayoutBase::class, static function () use (&$calls, $resultClass): LazyFactoryLayoutBase {
            ++$calls;

            return new $resultClass();
        });

        $proxy = self::resolve($container, $context);
        self::assertSame(0, $calls);

        try {
            $proxy->read();
            self::fail('An incompatible factory result must be rejected.');
        } catch (ContainerException $exception) {
            self::assertStringContainsString(LazyFactoryLayoutBase::class, $exception->getMessage());
            self::assertStringContainsString($resultClass, $exception->getMessage());
        }

        self::assertSame(1, $calls);
    }

    /**
     * @return iterable<string, array{class-string<LazyFactoryLayoutBase>}>
     */
    public static function incompatibleProxySubclasses(): iterable
    {
        yield 'extra instance property' => [LazyFactoryExtraPropertyChild::class];
        yield 'extra static property' => [LazyFactoryExtraStaticChild::class];
        yield 'clone override' => [LazyFactoryCloneChild::class];
        yield 'destructor override' => [LazyFactoryDestructorChild::class];
    }

    /**
     * @param class-string<LazyFactoryLayoutBase> $proxyClass
     */
    #[DataProvider('incompatibleProxySubclasses')]
    public function test_incompatible_parent_results_also_report_the_registration(string $proxyClass): void
    {
        self::requireNativeLazyObjects();
        $container = new Container();
        $container->lazy($proxyClass, static fn (): LazyFactoryLayoutBase => new LazyFactoryLayoutBase());
        $proxy = $container->get($proxyClass);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage($proxyClass);
        $proxy->read();
    }

    /**
     * @return iterable<string, array{class-string<LazyFactoryLayoutBase>, string}>
     */
    public static function compatibleFactories(): iterable
    {
        foreach ([LazyFactoryLayoutBase::class, LazyFactorySameLayoutChild::class, LazyFactoryOverriddenPropertyChild::class, LazyFactoryOverriddenPrivatePropertyChild::class] as $target) {
            foreach (['direct', 'alias', 'nested', 'scope'] as $context) {
                yield $target . ' ' . $context => [$target, $context];
            }
        }
    }

    /**
     * @param class-string<LazyFactoryLayoutBase> $target
     */
    #[DataProvider('compatibleFactories')]
    public function test_compatible_factories_stay_deferred_and_run_once_per_proxy(string $target, string $context): void
    {
        self::requireNativeLazyObjects();
        $calls = 0;
        $container = new Container();
        $container->lazy($target, static function () use (&$calls): LazyFactoryLayoutBase {
            ++$calls;

            return new LazyFactoryLayoutBase();
        });
        if ($target !== LazyFactoryLayoutBase::class) {
            $container->bind(LazyFactoryLayoutBase::class, $target);
        }

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $proxy = self::resolve($container, $context);
            self::assertSame($attempt, $calls);
            self::assertSame($target, $proxy::class);
            self::assertSame('ready!', $proxy->read());
            self::assertSame('ready!', $proxy->read());
            self::assertSame($attempt + 1, $calls);
        }
    }

    public function test_an_incompatible_result_can_be_retried_with_a_compatible_result(): void
    {
        self::requireNativeLazyObjects();
        $calls = 0;
        $container = new Container();
        $container->lazy(LazyFactoryLayoutBase::class, static function () use (&$calls): LazyFactoryLayoutBase {
            return ++$calls === 1 ? new LazyFactoryExtraPropertyChild() : new LazyFactoryLayoutBase();
        });
        $proxy = $container->get(LazyFactoryLayoutBase::class);

        try {
            $proxy->read();
            self::fail('The first result should be incompatible.');
        } catch (ContainerException) {
        }

        self::assertSame('ready!', $proxy->read());
        self::assertSame('ready!', $proxy->read());
        self::assertSame(2, $calls);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidResultTypes(): iterable
    {
        yield 'null' => [null, 'null'];
        yield 'array' => [[], 'array'];
        yield 'unrelated object' => [new CompilableDependency(), CompilableDependency::class];
    }

    /**
     * @param mixed $result
     */
    #[DataProvider('invalidResultTypes')]
    public function test_invalid_result_types_name_the_registration_and_returned_type(mixed $result, string $type): void
    {
        self::requireNativeLazyObjects();
        $container = new Container();
        $container->lazy(LazyFactoryLayoutBase::class, static fn (): mixed => $result);
        $proxy = $container->get(LazyFactoryLayoutBase::class);

        try {
            $proxy->read();
            self::fail('The factory result should not fit the proxy.');
        } catch (ContainerException $exception) {
            self::assertStringContainsString(LazyFactoryLayoutBase::class, $exception->getMessage());
            self::assertStringContainsString($type, $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function userExceptions(): iterable
    {
        yield 'runtime exception' => [new RuntimeException('factory failed')];
        yield 'type error' => [new TypeError('factory failed')];
    }

    #[DataProvider('userExceptions')]
    public function test_exceptions_from_the_factory_propagate_unchanged(Throwable $failure): void
    {
        self::requireNativeLazyObjects();
        $container = new Container();
        $container->lazy(LazyFactoryLayoutBase::class, static function () use ($failure): never {
            throw $failure;
        });
        $proxy = $container->get(LazyFactoryLayoutBase::class);

        try {
            $proxy->read();
            self::fail('The factory should throw its own exception.');
        } catch (Throwable $exception) {
            self::assertSame($failure, $exception);
        }
    }

    public function test_the_eager_fallback_keeps_subtype_factory_results(): void
    {
        (new ReflectionProperty(DependencyResolver::class, 'lazyObjectsAvailable'))->setValue(null, false);
        $calls = 0;
        $container = new Container();
        $container->lazy(LazyFactoryLayoutBase::class, static function () use (&$calls): LazyFactoryExtraPropertyChild {
            ++$calls;

            return new LazyFactoryExtraPropertyChild();
        });

        $service = $container->get(LazyFactoryLayoutBase::class);
        self::assertSame(1, $calls);
        self::assertInstanceOf(LazyFactoryExtraPropertyChild::class, $service);
        self::assertSame('ready!', $service->read());
        self::assertSame('extra', $service->extra);
        self::assertSame(1, $calls);
    }

    public function test_a_propertyless_registration_keeps_its_eager_factory_result(): void
    {
        $result = new class() extends CompilableDependency {
            public string $extra = 'extra';
        };
        $calls = 0;
        $container = new Container();
        $container->lazy(CompilableDependency::class, static function () use (&$calls, $result): CompilableDependency {
            ++$calls;

            return $result;
        });

        self::assertSame($result, $container->get(CompilableDependency::class));
        self::assertSame(1, $calls);
    }

    private static function requireNativeLazyObjects(): void
    {
        if (!method_exists(ReflectionClass::class, 'newLazyProxy')) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }
    }

    private static function resolve(Container $container, string $context): LazyFactoryLayoutBase
    {
        if ($context === 'scope') {
            return $container->createScope()->get(LazyFactoryLayoutBase::class);
        }

        if ($context === 'nested') {
            return $container->get(LazyFactoryLayoutConsumer::class)->dependency;
        }

        if ($context === 'alias') {
            $container->alias('lazy.service', LazyFactoryLayoutBase::class);

            return $container->get('lazy.service');
        }

        return $container->get(LazyFactoryLayoutBase::class);
    }
}

class LazyFactoryLayoutBase
{
    public string $value = 'ready';

    private string $suffix = '!';

    public function read(): string
    {
        return $this->value . $this->suffix;
    }
}

final class LazyFactorySameLayoutChild extends LazyFactoryLayoutBase
{
}

final class LazyFactoryOverriddenPropertyChild extends LazyFactoryLayoutBase
{
    public string $value = 'overridden';
}

final class LazyFactoryOverriddenPrivatePropertyChild extends LazyFactoryLayoutBase
{
    public string $suffix = 'overridden';
}

final class LazyFactoryExtraPropertyChild extends LazyFactoryLayoutBase
{
    public string $extra = 'extra';
}

final class LazyFactoryExtraStaticChild extends LazyFactoryLayoutBase
{
    public static int $extra = 1;
}

final class LazyFactoryCloneChild extends LazyFactoryLayoutBase
{
    public function __clone()
    {
    }
}

final class LazyFactoryDestructorChild extends LazyFactoryLayoutBase
{
    public function __destruct()
    {
    }
}

final class LazyFactoryLayoutConsumer
{
    public function __construct(public LazyFactoryLayoutBase $dependency)
    {
    }
}
