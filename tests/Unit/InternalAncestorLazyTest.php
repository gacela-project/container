<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use ArrayObject;
use Closure;
use Gacela\Container\Attribute\Lazy;
use Gacela\Container\Container;
use Gacela\Container\ContainerInterface;
use Gacela\Container\DependencyResolver;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\ConstructionCounter;
use GacelaTest\Fake\DeepLazyArrayBag;
use GacelaTest\Fake\DeepLazyStdClassService;
use GacelaTest\Fake\ForwardingContainer;
use GacelaTest\Fake\LazyArrayBag;
use GacelaTest\Fake\LazyStdClassService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use WeakReference;

final class InternalAncestorLazyTest extends TestCase
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

    /**
     * @return iterable<string, array{class-string<LazyArrayBag>, bool, bool}>
     */
    public static function unsupportedClasses(): iterable
    {
        foreach ([LazyArrayBag::class, DeepLazyArrayBag::class] as $class) {
            foreach ([false, true] as $forceEager) {
                foreach ([false, true] as $nested) {
                    yield $class . ($forceEager ? ' eager fallback' : ' native') . ($nested ? ' nested' : ' direct') => [$class, $forceEager, $nested];
                }
            }
        }
    }

    #[DataProvider('unsupportedClasses')]
    public function test_internal_descendants_build_eagerly_with_constructor_state_and_injection(string $class, bool $forceEager, bool $nested): void
    {
        self::inMode($forceEager, static function () use ($class, $nested): void {
            $container = new Container();
            $dependency = new ClassWithoutDependencies();
            $container->set(ClassWithoutDependencies::class, $dependency);
            if ($nested && $class !== LazyArrayBag::class) {
                $container->bind(LazyArrayBag::class, $class);
            }

            for ($attempt = 1; $attempt <= 2; ++$attempt) {
                $bag = $nested
                    ? $container->resolve(static fn (LazyArrayBag $bag): LazyArrayBag => $bag)
                    : $container->get($class);

                self::assertSame($class, $bag::class);
                self::assertSame($attempt, ConstructionCounter::countFor($class));
                self::assertSame(['configured' => 'internal state'], $bag->getArrayCopy());
                self::assertSame($dependency, $bag->dependency);
                self::assertSame($dependency, $bag->propertyDependency);
                self::assertSame($dependency, $bag->setterDependency);
            }
        });
    }

    /**
     * @return iterable<string, array{class-string<LazyStdClassService>, bool}>
     */
    public static function supportedClasses(): iterable
    {
        foreach ([LazyStdClassService::class, DeepLazyStdClassService::class] as $class) {
            yield $class . ' native' => [$class, false];
            yield $class . ' eager fallback' => [$class, true];
        }
    }

    #[DataProvider('supportedClasses')]
    public function test_stdclass_descendants_remain_lazy_when_supported(string $class, bool $forceEager): void
    {
        self::inMode($forceEager, static function () use ($class, $forceEager): void {
            $container = new Container();
            $service = $container->get($class);

            self::assertSame($forceEager ? 1 : 0, ConstructionCounter::countFor($class));
            if (!$forceEager) {
                self::assertTrue((new ReflectionClass($class))->isUninitializedLazyObject($service));
            }

            self::assertInstanceOf(ClassWithoutDependencies::class, $service->dependency);
            self::assertSame(1, ConstructionCounter::countFor($class));
        });
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function modes(): iterable
    {
        yield 'native' => [false];
        yield 'eager fallback' => [true];
    }

    #[DataProvider('modes')]
    public function test_unsupported_factory_results_keep_their_identity_and_scope_facade_without_retaining_it(bool $forceEager): void
    {
        $gcEnabled = gc_enabled();
        gc_disable();

        try {
            self::inMode($forceEager, static function (): void {
                $parent = new Container();
                $scope = $parent->createScope();
                $dependency = new ClassWithoutDependencies();
                $scope->set(ClassWithoutDependencies::class, $dependency);
                $facade = new ForwardingContainer($scope);
                $scope->withSelfReference($facade);
                $received = null;
                $created = null;
                $scope->lazy(LazyArrayBag::class, static function (ContainerInterface $current) use (&$received, &$created): DeepLazyArrayBag {
                    $received = $current;
                    $created = new DeepLazyArrayBag($current->get(ClassWithoutDependencies::class));

                    return $created;
                });

                $bag = $facade->get(LazyArrayBag::class);

                self::assertSame($facade, $received);
                self::assertSame($created, $bag);
                self::assertSame($dependency, $bag->dependency);
                self::assertSame(['configured' => 'internal state'], $bag->getArrayCopy());
                self::assertSame(1, ConstructionCounter::countFor(DeepLazyArrayBag::class));

                $received = null;
                $weakScope = WeakReference::create($scope);
                unset($scope, $facade);
                self::assertNull($weakScope->get());
            });
        } finally {
            if ($gcEnabled) {
                gc_enable();
            }
        }
    }

    #[DataProvider('modes')]
    public function test_supported_stdclass_factories_defer_and_keep_the_scope_only_until_initialization(bool $forceEager): void
    {
        $gcEnabled = gc_enabled();
        gc_disable();

        try {
            self::inMode($forceEager, static function () use ($forceEager): void {
                $parent = new Container();
                $scope = $parent->createScope();
                $dependency = new ClassWithoutDependencies();
                $scope->set(ClassWithoutDependencies::class, $dependency);
                $facade = new ForwardingContainer($scope);
                $scope->withSelfReference($facade);
                $weakFacade = WeakReference::create($facade);
                $weakScope = WeakReference::create($scope);
                $calls = 0;
                $scope->lazy(LazyStdClassService::class, static function (ContainerInterface $current) use ($weakFacade, &$calls): LazyStdClassService {
                    ++$calls;
                    self::assertSame($weakFacade->get(), $current);

                    return new LazyStdClassService($current->get(ClassWithoutDependencies::class));
                });

                $service = $facade->get(LazyStdClassService::class);

                self::assertSame($forceEager ? 1 : 0, $calls);
                unset($scope, $facade);
                self::assertSame(!$forceEager, $weakScope->get() !== null);
                self::assertSame($dependency, $service->dependency);
                self::assertSame(1, $calls);
                self::assertSame(1, ConstructionCounter::countFor(LazyStdClassService::class));
                self::assertNull($weakScope->get());
            });
        } finally {
            if ($gcEnabled) {
                gc_enable();
            }
        }
    }

    #[DataProvider('modes')]
    public function test_unsupported_lazy_singletons_keep_parent_and_scope_ownership(bool $forceEager): void
    {
        self::inMode($forceEager, static function (): void {
            $parent = new Container();
            $parentDependency = new ClassWithoutDependencies();
            $parent->set(ClassWithoutDependencies::class, $parentDependency);
            $parent->singleton(LazyArrayBag::class);
            $shared = $parent->get(LazyArrayBag::class);
            $scope = $parent->createScope();
            $localDependency = new ClassWithoutDependencies();
            $scope->set(ClassWithoutDependencies::class, $localDependency);

            self::assertSame($shared, $scope->get(LazyArrayBag::class));
            self::assertSame($parentDependency, $shared->dependency);
            $scope->singleton(LazyArrayBag::class);
            $local = $scope->get(LazyArrayBag::class);

            self::assertNotSame($shared, $local);
            self::assertSame($local, $scope->get(LazyArrayBag::class));
            self::assertSame($localDependency, $local->dependency);
            self::assertSame($localDependency, $local->propertyDependency);
            self::assertSame($localDependency, $local->setterDependency);
            self::assertSame(2, ConstructionCounter::countFor(LazyArrayBag::class));
        });
    }

    public function test_the_issues_empty_internal_descendant_already_uses_the_no_property_fallback(): void
    {
        $example = new #[Lazy] class() extends ArrayObject {};
        $bag = (new Container())->get($example::class);

        self::assertSame($example::class, $bag::class);
        self::assertSame([], $bag->getArrayCopy());
    }

    public function test_an_explicit_internal_class_registration_uses_eager_construction(): void
    {
        $container = new Container();
        $container->lazy(RuntimeException::class);
        $exception = $container->get(RuntimeException::class);

        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertSame('', $exception->getMessage());
    }

    public function test_class_eligibility_is_preserved_across_static_cache_reset(): void
    {
        if (!method_exists(ReflectionClass::class, 'newLazyGhost')) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $container = new Container();
        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            self::assertInstanceOf(DeepLazyArrayBag::class, $container->get(DeepLazyArrayBag::class));
            $supported = $container->get(DeepLazyStdClassService::class);
            self::assertTrue((new ReflectionClass(DeepLazyStdClassService::class))->isUninitializedLazyObject($supported));
            self::assertSame($attempt, ConstructionCounter::countFor(DeepLazyArrayBag::class));
            Container::resetStaticCaches();
        }
    }

    private static function inMode(bool $forceEager, Closure $test): void
    {
        if (!$forceEager && !method_exists(ReflectionClass::class, 'newLazyGhost')) {
            self::markTestSkipped('Native lazy objects require PHP 8.4');
        }

        $available = new ReflectionProperty(DependencyResolver::class, 'lazyObjectsAvailable');
        $previous = $available->getValue();
        if ($forceEager) {
            $available->setValue(null, false);
        }

        try {
            $test();
        } finally {
            if ($forceEager) {
                $available->setValue(null, $previous);
            }
        }
    }
}
