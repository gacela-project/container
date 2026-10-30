<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\BindingResolver;
use Gacela\Container\Container;
use Gacela\Container\ContainerInterface;
use Gacela\Container\DependencyCacheManager;
use Gacela\Container\DependencyResolver;
use Gacela\Container\Exception\CircularDependencyException;
use Gacela\Container\Exception\ContainerException;
use Gacela\Container\Exception\DependencyNotFoundException;
use GacelaTest\Fake\AbstractService;
use GacelaTest\Fake\AlternativeChainedRepository;
use GacelaTest\Fake\ChainedRepository;
use GacelaTest\Fake\ConstructionCounter;
use GacelaTest\Fake\ForwardingContainer;
use GacelaTest\Fake\InMemoryRepository;
use GacelaTest\Fake\PrivateConstructorService;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\SecondaryRepositoryInterface;
use GacelaTest\Fake\ServiceWithOptionalRepository;
use GacelaTest\Fake\ServiceWithRepository;
use GacelaTest\Fake\TertiaryRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Throwable;

final class InterfaceBindingChainTest extends TestCase
{
    protected function setUp(): void
    {
        ConstructionCounter::reset();
    }

    #[DataProvider('terminalRegistrations')]
    public function test_direct_and_nested_chains_use_the_terminal_registration(string $registration, bool $shared): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, SecondaryRepositoryInterface::class);
        $factory = static fn (): ChainedRepository => new ChainedRepository();
        match ($registration) {
            'class' => $container->bind(SecondaryRepositoryInterface::class, ChainedRepository::class),
            'bound object' => $container->bind(SecondaryRepositoryInterface::class, new ChainedRepository()),
            'bound closure' => $container->bind(SecondaryRepositoryInterface::class, $factory),
            'singleton class' => $container->singleton(SecondaryRepositoryInterface::class, ChainedRepository::class),
            'singleton closure' => $container->singleton(SecondaryRepositoryInterface::class, $factory),
            'stored object' => $container->set(SecondaryRepositoryInterface::class, new ChainedRepository()),
            'stored closure' => $container->set(SecondaryRepositoryInterface::class, $factory),
            'factory closure' => $container->set(SecondaryRepositoryInterface::class, $container->factory($factory)),
            'alias' => $container->alias(SecondaryRepositoryInterface::class, ChainedRepository::class),
        };

        self::assertTrue($container->has(RepositoryInterface::class));
        $first = $container->get(RepositoryInterface::class);
        $nested = $container->get(ServiceWithRepository::class)->repository;
        $terminal = $container->get(SecondaryRepositoryInterface::class);
        self::assertInstanceOf(ChainedRepository::class, $first);
        self::assertInstanceOf(ChainedRepository::class, $nested);
        self::assertInstanceOf(ChainedRepository::class, $terminal);
        if ($shared) {
            self::assertSame($terminal, $first);
            self::assertSame($terminal, $nested);
        } else {
            self::assertNotSame($terminal, $first);
            self::assertNotSame($first, $nested);
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function terminalRegistrations(): iterable
    {
        foreach (['class', 'bound closure', 'factory closure', 'alias'] as $registration) {
            yield $registration => [$registration, false];
        }
        foreach (['bound object', 'singleton class', 'singleton closure', 'stored object', 'stored closure'] as $registration) {
            yield $registration => [$registration, true];
        }
    }

    public function test_standalone_resolvers_can_follow_interface_bindings_without_an_owner(): void
    {
        $repository = new ChainedRepository();
        $bindings = [
            RepositoryInterface::class => SecondaryRepositoryInterface::class,
            SecondaryRepositoryInterface::class => $repository,
        ];
        $resolver = new DependencyResolver(bindings: $bindings);
        self::assertSame([$repository], $resolver->resolveDependencies(ServiceWithRepository::class));
        $bindingsResolver = new BindingResolver($bindings);
        self::assertSame($repository, $bindingsResolver->resolve(RepositoryInterface::class, new DependencyCacheManager($bindings)));
    }

    public function test_a_chain_can_follow_multiple_interface_links(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, SecondaryRepositoryInterface::class);
        $container->bind(SecondaryRepositoryInterface::class, TertiaryRepositoryInterface::class);
        $container->singleton(TertiaryRepositoryInterface::class, ChainedRepository::class);

        $first = $container->get(RepositoryInterface::class);
        self::assertInstanceOf(ChainedRepository::class, $first);
        self::assertSame($first, $container->get(SecondaryRepositoryInterface::class));
        self::assertSame($first, $container->get(ServiceWithRepository::class)->repository);
    }

    public function test_make_overrides_and_graphs_follow_interfaces_and_stop_at_concrete_targets(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, SecondaryRepositoryInterface::class);
        $container->bind(SecondaryRepositoryInterface::class, TertiaryRepositoryInterface::class);
        $container->bind(TertiaryRepositoryInterface::class, ChainedRepository::class);
        $container->bind(ChainedRepository::class, AlternativeChainedRepository::class);

        $made = $container->make(RepositoryInterface::class, ['value' => 42]);
        self::assertSame(ChainedRepository::class, $made::class);
        self::assertSame(42, $made->value);
        self::assertSame(ChainedRepository::class, $container->dependencyGraph(ServiceWithRepository::class)->children[0]->className);
        self::assertSame(1, ConstructionCounter::countFor(ChainedRepository::class));
    }

    #[DataProvider('ownerCrossingModes')]
    public function test_a_binding_chain_can_revisit_an_id_on_a_different_owner(string $mode): void
    {
        $parent = new Container();
        $parent->bind(SecondaryRepositoryInterface::class, RepositoryInterface::class);
        $parent->bind(RepositoryInterface::class, TertiaryRepositoryInterface::class);
        $parent->singleton(TertiaryRepositoryInterface::class, ChainedRepository::class);
        $scope = $parent->createScope();
        $scope->bind(RepositoryInterface::class, SecondaryRepositoryInterface::class);

        if ($mode === 'graph') {
            $graph = $scope->dependencyGraph(ServiceWithRepository::class);
            self::assertSame(ChainedRepository::class, $graph->children[0]->className);
            self::assertFalse($graph->hasCycle());
            self::assertSame(0, ConstructionCounter::countFor(ChainedRepository::class));
            return;
        }

        $observed = null;
        $calls = 0;
        if ($mode === 'hook') {
            $scope->afterResolving(ChainedRepository::class, static function (ChainedRepository $repository, ContainerInterface $owner) use (&$observed, &$calls): void {
                ++$calls;
                $observed = $owner->get(RepositoryInterface::class);
                self::assertSame($repository, $observed);
            });
        }

        $actual = $mode === 'make'
            ? $scope->make(RepositoryInterface::class, ['value' => 73])
            : $scope->get(RepositoryInterface::class);
        self::assertInstanceOf(ChainedRepository::class, $actual);
        if ($mode === 'make') {
            self::assertSame(73, $actual->value);
            self::assertNotSame($actual, $parent->get(TertiaryRepositoryInterface::class));
        } else {
            self::assertSame($parent->get(TertiaryRepositoryInterface::class), $actual);
            self::assertSame($actual, $scope->get(ServiceWithRepository::class)->repository);
        }
        if ($mode === 'hook') {
            self::assertSame($actual, $observed);
            self::assertSame(1, $calls);
        }
    }

    #[DataProvider('ownerCrossingModes')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_repeating_the_same_owner_and_id_still_closes_a_binding_cycle(string $mode): void
    {
        ini_set('memory_limit', '64M');
        set_time_limit(5);
        $parent = new Container();
        $parent->bind(SecondaryRepositoryInterface::class, RepositoryInterface::class);
        $parent->bind(RepositoryInterface::class, SecondaryRepositoryInterface::class);
        $scope = $parent->createScope();
        $scope->bind(RepositoryInterface::class, SecondaryRepositoryInterface::class);
        if ($mode === 'graph') {
            $graph = $scope->dependencyGraph(ServiceWithRepository::class);
            self::assertTrue($graph->hasCycle());
            self::assertTrue($graph->children[0]->repeated);
            return;
        }
        if ($mode === 'hook') {
            $scope->afterResolving(ChainedRepository::class, static function (): void {
                self::fail('An unfinished cycle has no completed instance');
            });
        }

        $this->expectException(CircularDependencyException::class);
        if ($mode === 'make') {
            $scope->make(RepositoryInterface::class, ['value' => 73]);
        } else {
            $scope->get(RepositoryInterface::class);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ownerCrossingModes(): iterable
    {
        foreach (['direct', 'make', 'graph', 'hook'] as $mode) {
            yield $mode => [$mode];
        }
    }

    public function test_make_overrides_and_graphs_keep_the_binding_entry_owner(): void
    {
        $parent = new Container();
        $parent->bind(RepositoryInterface::class, SecondaryRepositoryInterface::class);
        $parent->bind(SecondaryRepositoryInterface::class, ChainedRepository::class);
        $scope = $parent->createScope();
        $scope->bind(SecondaryRepositoryInterface::class, AlternativeChainedRepository::class);

        $inherited = $scope->make(RepositoryInterface::class, ['value' => 17]);
        self::assertSame(ChainedRepository::class, $inherited::class);
        self::assertSame(17, $inherited->value);
        self::assertSame(ChainedRepository::class, $scope->dependencyGraph(ServiceWithRepository::class)->children[0]->className);

        $scope->bind(RepositoryInterface::class, SecondaryRepositoryInterface::class);
        $local = $scope->make(RepositoryInterface::class, ['value' => 23]);
        self::assertSame(AlternativeChainedRepository::class, $local::class);
        self::assertSame(23, $local->value);
        self::assertSame(AlternativeChainedRepository::class, $scope->dependencyGraph(ServiceWithRepository::class)->children[0]->className);
    }

    #[DataProvider('interfaceChainLengths')]
    public function test_make_overrides_still_follow_an_entry_class_string_binding_despite_a_stored_object(bool $long): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, $long ? SecondaryRepositoryInterface::class : ChainedRepository::class);
        $container->bind(SecondaryRepositoryInterface::class, ChainedRepository::class);
        $stored = new InMemoryRepository();
        $container->set(RepositoryInterface::class, $stored);

        $made = $container->make(RepositoryInterface::class, ['value' => 41]);
        self::assertSame(ChainedRepository::class, $made::class);
        self::assertSame(41, $made->value);
        self::assertNotSame($stored, $made);
        self::assertSame($stored, $container->get(RepositoryInterface::class));
    }

    public function test_type_analysis_follows_terminal_aliases_to_class_registrations(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, SecondaryRepositoryInterface::class);
        $container->alias(SecondaryRepositoryInterface::class, 'named repository');
        $container->bind('named repository', ChainedRepository::class);

        $made = $container->make(RepositoryInterface::class, ['value' => 31]);
        self::assertSame(ChainedRepository::class, $made::class);
        self::assertSame(31, $made->value);
        self::assertSame(ChainedRepository::class, $container->dependencyGraph(ServiceWithRepository::class)->children[0]->className);
    }

    public function test_type_analysis_does_not_fall_through_a_scope_stored_terminal_to_a_parent_binding(): void
    {
        $parent = new Container();
        $parent->bind(SecondaryRepositoryInterface::class, ChainedRepository::class);
        $scope = $parent->createScope();
        $scope->bind(RepositoryInterface::class, SecondaryRepositoryInterface::class);
        $calls = 0;
        $scope->set(SecondaryRepositoryInterface::class, static function () use (&$calls): ChainedRepository {
            ++$calls;
            return new ChainedRepository();
        });

        self::assertSame(SecondaryRepositoryInterface::class, $scope->dependencyGraph(ServiceWithRepository::class)->children[0]->className);
        try {
            $scope->make(RepositoryInterface::class, ['value' => 31]);
            self::fail('A stored definition has no constructor arguments to override');
        } catch (DependencyNotFoundException) {
            self::assertSame(0, $calls);
        }
    }

    public function test_type_analysis_does_not_invoke_terminal_factories(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, SecondaryRepositoryInterface::class);
        $calls = 0;
        $container->bind(SecondaryRepositoryInterface::class, static function () use (&$calls): ChainedRepository {
            ++$calls;
            return new ChainedRepository();
        });

        $graph = $container->dependencyGraph(ServiceWithRepository::class);
        self::assertSame(SecondaryRepositoryInterface::class, $graph->children[0]->className);
        self::assertSame([], $graph->children[0]->children);
        try {
            $container->make(RepositoryInterface::class, ['value' => 42]);
            self::fail('A factory has no constructor arguments to override');
        } catch (DependencyNotFoundException) {
            self::assertSame(0, $calls);
            self::assertSame(0, ConstructionCounter::countFor(ChainedRepository::class));
        }
    }

    #[DataProvider('interfaceChainLengths')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_make_rejects_binding_cycles_while_graphs_mark_them(bool $alias): void
    {
        ini_set('memory_limit', '64M');
        set_time_limit(5);
        $container = new Container();
        $container->bind(RepositoryInterface::class, SecondaryRepositoryInterface::class);
        if ($alias) {
            $container->alias(SecondaryRepositoryInterface::class, RepositoryInterface::class);
        } else {
            $container->bind(SecondaryRepositoryInterface::class, RepositoryInterface::class);
        }

        try {
            $container->make(RepositoryInterface::class, ['value' => 42]);
            self::fail('Expected a circular binding exception');
        } catch (CircularDependencyException) {
            $graph = $container->dependencyGraph(ServiceWithRepository::class);
            self::assertTrue($graph->hasCycle());
            self::assertTrue($graph->children[0]->repeated);
            self::assertSame(RepositoryInterface::class, $graph->children[0]->className);
        }
    }

    public function test_a_chain_keeps_terminal_factory_values_and_exceptions(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, SecondaryRepositoryInterface::class);
        foreach ([null, 'value', [1, 2]] as $value) {
            $container->bind(SecondaryRepositoryInterface::class, static fn () => $value);
            self::assertSame($value, $container->get(RepositoryInterface::class));
        }
        $error = new RuntimeException('Factory failed');
        $container->bind(SecondaryRepositoryInterface::class, static fn () => throw $error);
        $this->expectExceptionObject($error);
        $container->get(RepositoryInterface::class);
    }

    #[DataProvider('terminalOwners')]
    public function test_a_chain_preserves_the_terminal_owner_and_scope_shadowing(bool $localEntry): void
    {
        $parent = new Container();
        $parent->bind(RepositoryInterface::class, SecondaryRepositoryInterface::class);
        $owners = [];
        $parent->singleton(SecondaryRepositoryInterface::class, static function (Container $owner) use (&$owners): ChainedRepository {
            $owners[] = $owner;
            return new ChainedRepository();
        });
        $scope = $parent->createScope();
        $local = new ChainedRepository();
        $scope->set(SecondaryRepositoryInterface::class, $local);
        if ($localEntry) {
            $scope->bind(RepositoryInterface::class, SecondaryRepositoryInterface::class);
        }

        $direct = $scope->get(RepositoryInterface::class);
        self::assertSame($direct, $scope->get(ServiceWithRepository::class)->repository);
        if ($localEntry) {
            self::assertSame($local, $direct);
            self::assertSame([], $owners);
        } else {
            self::assertNotSame($local, $direct);
            self::assertSame($parent->get(SecondaryRepositoryInterface::class), $direct);
            self::assertSame([$parent], $owners);
        }
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function terminalOwners(): iterable
    {
        yield 'inherited entry' => [false];
        yield 'local entry' => [true];
    }

    public function test_contextual_selection_and_registered_defaults_keep_their_precedence(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, SecondaryRepositoryInterface::class);
        $container->bind(SecondaryRepositoryInterface::class, TertiaryRepositoryInterface::class);
        $container->singleton(TertiaryRepositoryInterface::class, ChainedRepository::class);
        $container->when(ServiceWithRepository::class)->needs(RepositoryInterface::class)->give(InMemoryRepository::class);

        self::assertInstanceOf(InMemoryRepository::class, $container->get(ServiceWithRepository::class)->repository);
        self::assertSame(
            $container->get(SecondaryRepositoryInterface::class),
            $container->get(ServiceWithOptionalRepository::class)->repository,
        );

        $container->when(ServiceWithRepository::class)->needs(RepositoryInterface::class)->give(SecondaryRepositoryInterface::class);
        self::assertSame(
            $container->get(SecondaryRepositoryInterface::class),
            $container->get(ServiceWithRepository::class)->repository,
        );
    }

    public function test_terminal_hooks_and_facades_follow_normal_dispatch(): void
    {
        $container = new Container();
        $facade = new ForwardingContainer($container);
        $container->withSelfReference($facade);
        $container->bind(RepositoryInterface::class, SecondaryRepositoryInterface::class);
        $owners = [];
        $container->singleton(SecondaryRepositoryInterface::class, static function (ContainerInterface $owner) use (&$owners): ChainedRepository {
            $owners[] = $owner;
            return new ChainedRepository();
        });
        $calls = 0;
        $container->afterResolving(SecondaryRepositoryInterface::class, static function () use (&$calls): void {
            ++$calls;
        });
        $first = $container->get(RepositoryInterface::class);
        self::assertSame($first, $container->get(ServiceWithRepository::class)->repository);
        self::assertSame([$facade], $owners);
        self::assertSame(1, $calls);
    }

    #[DataProvider('publishedRegistrations')]
    public function test_a_terminal_factory_can_publish_a_ready_object_before_reentry_without_hooks(bool $stored): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, SecondaryRepositoryInterface::class);
        $repository = new ChainedRepository();
        $observed = null;
        $calls = 0;
        $container->bind(SecondaryRepositoryInterface::class, static function (ContainerInterface $owner) use ($repository, $stored, &$observed, &$calls): ChainedRepository {
            ++$calls;
            if ($stored) {
                $owner->set(SecondaryRepositoryInterface::class, $repository);
            } else {
                $owner->bind(SecondaryRepositoryInterface::class, $repository);
            }
            $observed = $owner->get(RepositoryInterface::class);

            return $repository;
        });

        self::assertSame($repository, $container->get(RepositoryInterface::class));
        self::assertSame($repository, $observed);
        self::assertSame($repository, $container->get(ServiceWithRepository::class)->repository);
        self::assertSame(1, $calls);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function publishedRegistrations(): iterable
    {
        yield 'stored publication' => [true];
        yield 'binding publication' => [false];
    }

    #[DataProvider('interfaceChainLengths')]
    public function test_a_terminal_hook_can_read_the_shared_entry_again(bool $long): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, SecondaryRepositoryInterface::class);
        if ($long) {
            $container->bind(SecondaryRepositoryInterface::class, TertiaryRepositoryInterface::class);
        }
        $container->singleton($long ? TertiaryRepositoryInterface::class : SecondaryRepositoryInterface::class, ChainedRepository::class);
        $observed = null;
        $container->afterResolving(SecondaryRepositoryInterface::class, static function (ChainedRepository $repository, ContainerInterface $owner) use (&$observed): void {
            $observed = $owner->get(RepositoryInterface::class);
            self::assertSame($repository, $observed);
        });

        self::assertSame($container->get(RepositoryInterface::class), $observed);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function interfaceChainLengths(): iterable
    {
        yield 'one interface link' => [false];
        yield 'two interface links' => [true];
    }

    #[DataProvider('lazyModes')]
    public function test_a_chain_keeps_lazy_terminal_construction(bool $forceEager): void
    {
        $available = new ReflectionProperty(DependencyResolver::class, 'lazyObjectsAvailable');
        $previous = $available->getValue();
        if ($forceEager) {
            $available->setValue(null, false);
        }
        try {
            $container = new Container();
            $container->bind(RepositoryInterface::class, SecondaryRepositoryInterface::class);
            $container->lazy(SecondaryRepositoryInterface::class, ChainedRepository::class);
            $direct = $container->get(RepositoryInterface::class);
            $nested = $container->get(ServiceWithRepository::class)->repository;
            self::assertInstanceOf(ChainedRepository::class, $direct);
            self::assertInstanceOf(ChainedRepository::class, $nested);
            self::assertSame($forceEager || !method_exists(ReflectionClass::class, 'newLazyGhost') ? 2 : 0, ConstructionCounter::countFor(ChainedRepository::class));
            ++$direct->value;
            ++$nested->value;
            self::assertSame(2, ConstructionCounter::countFor(ChainedRepository::class));
        } finally {
            $available->setValue(null, $previous);
        }
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function lazyModes(): iterable
    {
        yield 'native when available' => [false];
        yield 'eager fallback' => [true];
    }

    /**
     * @param class-string<Throwable> $exception
     */
    #[DataProvider('invalidTerminals')]
    public function test_invalid_chain_terminals_raise_container_exceptions(?string $target, bool $nested, string $exception): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, SecondaryRepositoryInterface::class);
        if ($target !== null) {
            $container->bind(SecondaryRepositoryInterface::class, $target);
        }
        $this->expectException($exception);
        $container->get($nested ? ServiceWithRepository::class : RepositoryInterface::class);
    }

    /**
     * @return iterable<string, array{?string, bool, class-string<Throwable>}>
     */
    public static function invalidTerminals(): iterable
    {
        foreach ([false, true] as $nested) {
            $prefix = $nested ? 'nested ' : 'direct ';
            yield $prefix . 'unbound interface' => [null, $nested, DependencyNotFoundException::class];
            yield $prefix . 'missing class' => ['GacelaTest\\Fake\\MissingChainTarget', $nested, DependencyNotFoundException::class];
            yield $prefix . 'abstract class' => [AbstractService::class, $nested, ContainerException::class];
            yield $prefix . 'private constructor' => [PrivateConstructorService::class, $nested, ContainerException::class];
        }
    }

    #[DataProvider('cycleModes')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_interface_binding_cycles_are_bounded(string $kind, bool $nested): void
    {
        ini_set('memory_limit', '64M');
        set_time_limit(5);
        $container = new Container();
        $container->bind(RepositoryInterface::class, SecondaryRepositoryInterface::class);
        match ($kind) {
            'two interfaces' => $container->bind(SecondaryRepositoryInterface::class, RepositoryInterface::class),
            'self interface' => $container->bind(SecondaryRepositoryInterface::class, SecondaryRepositoryInterface::class),
            'entry self' => $container->bind(RepositoryInterface::class, RepositoryInterface::class),
            'interface alias' => $container->alias(SecondaryRepositoryInterface::class, RepositoryInterface::class),
            'factory recursion' => $container->bind(SecondaryRepositoryInterface::class, static fn (ContainerInterface $owner) => $owner->get(RepositoryInterface::class)),
        };
        try {
            $container->get($nested ? ServiceWithRepository::class : RepositoryInterface::class);
            self::fail('Expected a circular binding exception');
        } catch (CircularDependencyException) {
            if ($kind === 'entry self') {
                $container->bind(RepositoryInterface::class, SecondaryRepositoryInterface::class);
            }
            if ($kind === 'interface alias') {
                $container->alias(SecondaryRepositoryInterface::class, ChainedRepository::class);
            } else {
                $container->bind(SecondaryRepositoryInterface::class, ChainedRepository::class);
            }
            self::assertInstanceOf(ChainedRepository::class, $container->get(RepositoryInterface::class));
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function cycleModes(): iterable
    {
        foreach ([false, true] as $nested) {
            foreach (['two interfaces', 'self interface', 'entry self', 'interface alias', 'factory recursion'] as $kind) {
                yield $kind . ($nested ? ' nested' : ' direct') => [$kind, $nested];
            }
        }
    }
}
