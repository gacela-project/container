<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Closure;
use Gacela\Container\Container;
use Gacela\Container\ContainerInterface;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\ForwardingContainer;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\PersonInterface;
use GacelaTest\Fake\SingletonAttributeService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WeakReference;

use function is_string;

final class SingletonEvictionTest extends TestCase
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
     * @param class-string $id
     * @param class-string|Closure|null $concrete
     */
    #[DataProvider('evictionProvider')]
    public function test_eviction_rebuilds_singletons_and_preserves_their_registration(string $id, string|Closure|null $concrete, string $operation): void
    {
        $container = new Container();
        if ($concrete !== null) {
            $container->singleton($id, $concrete);
        }
        $container->alias('service.alias', $id);
        $bindings = $container->getBindings();
        $evictionIds = [$id, 'service.alias'];
        if (is_string($concrete) && $concrete !== $id) {
            $evictionIds[] = $concrete;
        }

        foreach ($evictionIds as $evictionId) {
            $first = $container->get($id);
            $container->{$operation}($evictionId);
            $rebuilt = $container->get($id);

            self::assertNotSame($first, $rebuilt);
            self::assertSame($rebuilt, $container->get($id));
            self::assertSame($rebuilt, $container->get('service.alias'));
            self::assertSame($bindings, $container->getBindings());
        }
    }

    /**
     * @return iterable<string, array{class-string, class-string|Closure|null, string}>
     */
    public static function evictionProvider(): iterable
    {
        foreach (self::singletonProvider() as $kind => [$id, $concrete]) {
            foreach (['forget', 'remove'] as $operation) {
                yield $kind . ' ' . $operation => [$id, $concrete, $operation];
            }
        }
    }

    /**
     * @param class-string $id
     * @param class-string|Closure|null $concrete
     */
    #[DataProvider('singletonProvider')]
    public function test_a_failed_hook_discards_the_half_configured_singleton(string $id, string|Closure|null $concrete): void
    {
        $container = new Container();
        if ($concrete !== null) {
            $container->singleton($id, $concrete);
        }
        $failed = null;
        $container->afterResolving($id, static function (object $instance) use (&$failed): void {
            if ($failed !== null) {
                return;
            }

            $failed = $instance;
            if ($instance instanceof Person) {
                $instance->name = 'half configured';
            } elseif ($instance instanceof SingletonAttributeService) {
                $instance->dependency = new ClassWithoutDependencies();
            }

            throw new RuntimeException('configuration failed');
        });

        try {
            $container->get($id);
            self::fail('the hook should have thrown');
        } catch (RuntimeException) {
        }

        $rebuilt = $container->get($id);

        self::assertNotSame($failed, $rebuilt);
        self::assertSame($rebuilt, $container->get($id));
        if ($rebuilt instanceof Person) {
            self::assertSame('', $rebuilt->name);
        }
    }

    /**
     * @return iterable<string, array{class-string, class-string|Closure|null}>
     */
    public static function singletonProvider(): iterable
    {
        yield 'class' => [Person::class, Person::class];
        yield 'attribute' => [SingletonAttributeService::class, null];
        yield 'callable' => [Person::class, static fn (): Person => new Person()];
        yield 'mapped class' => [PersonInterface::class, Person::class];
        yield 'mapped callable' => [PersonInterface::class, static fn (): Person => new Person()];
    }

    #[DataProvider('operationProvider')]
    public function test_scope_eviction_does_not_drop_a_parent_singleton(string $operation): void
    {
        $container = new Container();
        $container->singleton(PersonInterface::class, Person::class);
        $shared = $container->get(PersonInterface::class);
        $scope = $container->createScope();
        $scope->alias('service.alias', PersonInterface::class);
        $scope->{$operation}('service.alias');

        self::assertSame($shared, $container->get(PersonInterface::class));
        self::assertSame($shared, $scope->get(PersonInterface::class));
    }

    #[DataProvider('operationProvider')]
    public function test_scope_eviction_drops_only_its_own_callable_singleton(string $operation): void
    {
        $container = new Container();
        $container->singleton(PersonInterface::class, static fn (): Person => new Person('parent'));
        $parent = $container->get(PersonInterface::class);
        $scope = $container->createScope();
        $scope->singleton(PersonInterface::class, static fn (): Person => new Person('scope'));
        $first = $scope->get(PersonInterface::class);
        $scope->{$operation}(PersonInterface::class);
        $rebuilt = $scope->get(PersonInterface::class);

        self::assertNotSame($first, $rebuilt);
        self::assertSame('scope', $rebuilt->name);
        self::assertSame($parent, $container->get(PersonInterface::class));
    }

    #[DataProvider('operationProvider')]
    public function test_scope_eviction_drops_only_its_own_attribute_singleton(string $operation): void
    {
        $container = new Container();
        $scope = $container->createScope();
        $sibling = $container->createScope();
        $first = $scope->get(SingletonAttributeService::class);
        $other = $sibling->get(SingletonAttributeService::class);
        $scope->{$operation}(SingletonAttributeService::class);
        $rebuilt = $scope->get(SingletonAttributeService::class);

        self::assertNotSame($first, $rebuilt);
        self::assertSame($rebuilt, $scope->get(SingletonAttributeService::class));
        self::assertSame($other, $sibling->get(SingletonAttributeService::class));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function operationProvider(): iterable
    {
        yield 'forget' => ['forget'];
        yield 'remove' => ['remove'];
    }

    public function test_rebuilding_a_callable_singleton_uses_the_current_facade(): void
    {
        $container = new Container();
        $seen = null;
        $container->singleton(Person::class, static function (ContainerInterface $current) use (&$seen): Person {
            $seen = WeakReference::create($current);

            return new Person();
        });
        $original = new ForwardingContainer($container);
        $container->withSelfReference($original);
        $first = $container->get(Person::class);
        self::assertSame($original, $seen?->get());
        $replacement = new ForwardingContainer($container);
        $container->withSelfReference($replacement);
        $container->forget(Person::class);
        $rebuilt = $container->get(Person::class);

        self::assertNotSame($first, $rebuilt);
        self::assertSame($replacement, $seen?->get());
        unset($replacement);
        $container->forget(Person::class);
        $container->get(Person::class);

        self::assertSame($container, $seen?->get());
    }

    public function test_a_callable_singleton_still_memoizes_null_until_evicted(): void
    {
        $container = new Container();
        $calls = 0;
        $container->singleton('empty', static function () use (&$calls): null {
            ++$calls;

            return null;
        });

        self::assertNull($container->get('empty'));
        self::assertNull($container->get('empty'));
        self::assertSame(1, $calls);
        $container->forget('empty');
        self::assertNull($container->get('empty'));
        self::assertSame(2, $calls);
        $container->remove('empty');
        self::assertNull($container->get('empty'));
        self::assertSame(3, $calls);
    }

    public function test_registering_a_new_callable_singleton_drops_the_previous_memo(): void
    {
        $container = new Container();
        $container->singleton(Person::class, static fn (): Person => new Person('first'));
        $first = $container->get(Person::class);
        $container->singleton(Person::class, static fn (): Person => new Person('second'));
        $second = $container->get(Person::class);

        self::assertNotSame($first, $second);
        self::assertSame('second', $second->name);
    }

    public function test_a_running_singleton_factory_cannot_fill_a_replacement_registration(): void
    {
        $container = new Container();
        $container->singleton(Person::class, static function (ContainerInterface $current): Person {
            $current->singleton(Person::class, static fn (): Person => new Person('replacement'));

            return new Person('original');
        });
        $original = $container->get(Person::class);
        $replacement = $container->get(Person::class);

        self::assertSame('original', $original->name);
        self::assertSame('replacement', $replacement->name);
        self::assertNotSame($original, $replacement);
        self::assertSame($replacement, $container->get(Person::class));
    }

    public function test_an_exported_singleton_factory_cannot_fill_a_replacement_registration(): void
    {
        $container = new Container();
        $container->singleton(Person::class, static fn (): Person => new Person('original'));
        $exported = $container->getBindings()[Person::class];
        self::assertInstanceOf(Closure::class, $exported);
        $container->singleton(Person::class, static fn (): Person => new Person('replacement'));
        $original = $exported();
        $replacement = $container->get(Person::class);

        self::assertSame('original', $original->name);
        self::assertSame('replacement', $replacement->name);
        self::assertNotSame($original, $replacement);
        self::assertSame($original, $exported());
        self::assertSame($replacement, $container->get(Person::class));
    }

    public function test_an_exported_singleton_factory_keeps_its_own_memo_after_replacement(): void
    {
        $container = new Container();
        $container->singleton(Person::class, static fn (): Person => new Person('original'));
        $exported = $container->getBindings()[Person::class];
        self::assertInstanceOf(Closure::class, $exported);
        $original = $exported();
        $container->singleton(Person::class, static fn (): Person => new Person('replacement'));
        $replacement = $container->get(Person::class);

        self::assertSame('replacement', $replacement->name);
        self::assertSame($original, $exported());
        self::assertNotSame($original, $replacement);
    }

    public function test_callable_singleton_memoization_does_not_keep_its_owner_alive(): void
    {
        $wasEnabled = gc_enabled();
        gc_disable();

        try {
            $container = new Container();
            $container->singleton(Person::class, static fn (): Person => new Person());
            $container->get(Person::class);
            $reference = WeakReference::create($container);
            unset($container);

            self::assertNull($reference->get());
        } finally {
            if ($wasEnabled) {
                gc_enable();
            }
        }
    }
}
