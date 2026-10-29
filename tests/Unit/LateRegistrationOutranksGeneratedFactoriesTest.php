<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Closure;
use Gacela\Container\Container;
use GacelaTest\Fake\AlternativeCompilableDependency;
use GacelaTest\Fake\ClassWithDependencyWithoutDependencies;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\CompilableConsumer;
use GacelaTest\Fake\CompilableDependency;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\Graph\Diamond;
use GacelaTest\Fake\Graph\Leaf;
use GacelaTest\Fake\InMemoryRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class LateRegistrationOutranksGeneratedFactoriesTest extends TestCase
{
    /**
     * @param Closure(Container): void $register
     */
    #[DataProvider('dependencyRegistrations')]
    public function test_registered_dependencies_outrank_inlined_construction(Closure $register, bool $registerFirst): void
    {
        $factories = self::factoriesFor(ClassWithDependencyWithoutDependencies::class);
        $container = new Container();

        if ($registerFirst) {
            $register($container);
        }

        $container->useCompiledFactories($factories);

        if (!$registerFirst) {
            $register($container);
        }

        self::assertSame(
            $container->get(ClassWithoutDependencies::class),
            $container->get(ClassWithDependencyWithoutDependencies::class)->classWithoutDependencies,
        );
    }

    /**
     * @return iterable<string, array{Closure(Container): void, bool}>
     */
    public static function dependencyRegistrations(): iterable
    {
        $registrations = [
            'stored' => static function (Container $container): void {
                $container->set(ClassWithoutDependencies::class, new ClassWithoutDependencies());
            },
            'binding' => static function (Container $container): void {
                $container->bind(ClassWithoutDependencies::class, new ClassWithoutDependencies());
            },
            'singleton' => static function (Container $container): void {
                $container->singleton(ClassWithoutDependencies::class);
            },
            'alias' => static function (Container $container): void {
                $container->set('shared', new ClassWithoutDependencies());
                $container->alias(ClassWithoutDependencies::class, 'shared');
            },
        ];

        foreach ($registrations as $name => $register) {
            yield $name . ' before install' => [$register, true];
            yield $name . ' after install' => [$register, false];
        }
    }

    /**
     * @param Closure(Container): void $register
     */
    #[DataProvider('dependencyRegistrations')]
    public function test_the_compiler_refuses_registered_dependencies(Closure $register, bool $registerFirst): void
    {
        $container = new Container();

        if (!$registerFirst) {
            $container->compile([ClassWithDependencyWithoutDependencies::class]);
        }

        $register($container);

        self::assertNotContains(
            ClassWithDependencyWithoutDependencies::class,
            $container->compileReport([ClassWithDependencyWithoutDependencies::class])->compiled(),
        );
    }

    public function test_a_transitive_registration_outranks_inlined_construction(): void
    {
        $container = new Container();
        $container->useCompiledFactories(self::factoriesFor(Diamond::class));
        $leaf = new Leaf();
        $container->set(Leaf::class, $leaf);

        $diamond = $container->get(Diamond::class);

        self::assertSame($leaf, $diamond->left->shared->leaf);
        self::assertSame($leaf, $diamond->right->shared->leaf);
    }

    public function test_a_scope_override_outranks_inherited_generated_dependencies(): void
    {
        $container = new Container();
        $container->useCompiledFactories(self::factoriesFor(ClassWithDependencyWithoutDependencies::class));
        $scope = $container->createScope();
        $shared = new ClassWithoutDependencies();
        $scope->set(ClassWithoutDependencies::class, $shared);

        self::assertSame($shared, $scope->get(ClassWithDependencyWithoutDependencies::class)->classWithoutDependencies);
        self::assertNotSame($shared, $container->get(ClassWithDependencyWithoutDependencies::class)->classWithoutDependencies);
    }

    public function test_a_late_parent_registration_outranks_factories_pushed_to_scopes(): void
    {
        $container = new Container();
        $scope = $container->createScope();
        $container->useCompiledFactories(self::factoriesFor(ClassWithDependencyWithoutDependencies::class));
        $shared = new ClassWithoutDependencies();
        $container->set(ClassWithoutDependencies::class, $shared);

        self::assertSame($shared, $scope->get(ClassWithDependencyWithoutDependencies::class)->classWithoutDependencies);
    }

    public function test_custom_invokable_factories_still_work(): void
    {
        $factory = new class() {
            public function __invoke(): ClassWithoutDependencies
            {
                return new ClassWithoutDependencies();
            }
        };
        $container = new Container();
        $container->set('unrelated', true);
        $container->useCompiledFactories([ClassWithoutDependencies::class => $factory]);

        self::assertInstanceOf(ClassWithoutDependencies::class, $container->get(ClassWithoutDependencies::class));
    }

    public function test_registered_dependencies_still_win_after_resetting_static_caches(): void
    {
        $container = new Container();
        $container->useCompiledFactories(self::factoriesFor(ClassWithDependencyWithoutDependencies::class));
        $shared = new ClassWithoutDependencies();
        $container->set(ClassWithoutDependencies::class, $shared);

        Container::resetStaticCaches();

        self::assertSame($shared, $container->get(ClassWithDependencyWithoutDependencies::class)->classWithoutDependencies);
    }

    public function test_replacing_factories_replaces_their_dependency_metadata(): void
    {
        $container = new Container();
        $container->set(ClassWithoutDependencies::class, new ClassWithoutDependencies());
        $container->useCompiledFactories(self::factoriesFor(ClassWithDependencyWithoutDependencies::class));
        $container->get(ClassWithDependencyWithoutDependencies::class);
        $replacement = new ClassWithDependencyWithoutDependencies(new ClassWithoutDependencies());
        $container->useCompiledFactories([
            ClassWithDependencyWithoutDependencies::class => static fn (): ClassWithDependencyWithoutDependencies => $replacement,
        ]);

        self::assertSame($replacement, $container->get(ClassWithDependencyWithoutDependencies::class));
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_an_alias_to_an_autowirable_target_outranks_inlining(bool $registerFirst): void
    {
        $factories = self::factoriesFor(CompilableConsumer::class);
        $container = new Container();

        if ($registerFirst) {
            $container->alias(CompilableDependency::class, AlternativeCompilableDependency::class);
        }

        $container->useCompiledFactories($factories);
        $scope = $container->createScope();

        if (!$registerFirst) {
            $container->alias(CompilableDependency::class, AlternativeCompilableDependency::class);
        }

        self::assertInstanceOf(AlternativeCompilableDependency::class, $container->get(CompilableConsumer::class)->dependency);
        self::assertInstanceOf(AlternativeCompilableDependency::class, $scope->get(CompilableConsumer::class)->dependency);
        self::assertNotContains(CompilableConsumer::class, $container->compileReport([CompilableConsumer::class])->compiled());
    }

    public function test_a_bind_made_after_generated_factories_are_installed_wins(): void
    {
        $container = new Container();
        $container->useCompiledFactories([
            InMemoryRepository::class => static fn (): InMemoryRepository => new InMemoryRepository(),
        ]);

        $container->bind(InMemoryRepository::class, DatabaseRepository::class);

        self::assertInstanceOf(DatabaseRepository::class, $container->get(InMemoryRepository::class));
    }

    public function test_a_bind_made_before_generated_factories_are_installed_wins(): void
    {
        $container = new Container();
        $container->bind(InMemoryRepository::class, DatabaseRepository::class);

        $container->useCompiledFactories([
            InMemoryRepository::class => static fn (): InMemoryRepository => new InMemoryRepository(),
        ]);

        self::assertInstanceOf(DatabaseRepository::class, $container->get(InMemoryRepository::class));
    }

    public function test_a_singleton_made_after_generated_factories_are_installed_is_shared(): void
    {
        $container = new Container();
        $container->useCompiledFactories([
            InMemoryRepository::class => static fn (): InMemoryRepository => new InMemoryRepository(),
        ]);

        $container->singleton(InMemoryRepository::class);

        self::assertSame(
            $container->get(InMemoryRepository::class),
            $container->get(InMemoryRepository::class),
        );
    }

    public function test_a_stored_instance_outranks_a_generated_factory(): void
    {
        $instance = new InMemoryRepository();

        $container = new Container();
        $container->useCompiledFactories([
            InMemoryRepository::class => static fn (): InMemoryRepository => new InMemoryRepository(),
        ]);
        $container->set(InMemoryRepository::class, $instance);

        self::assertSame($instance, $container->get(InMemoryRepository::class));
    }

    public function test_a_class_with_no_registration_still_takes_the_generated_path(): void
    {
        $calls = 0;

        $container = new Container();
        $container->useCompiledFactories([
            InMemoryRepository::class => static function () use (&$calls): InMemoryRepository {
                ++$calls;
                return new InMemoryRepository();
            },
        ]);

        $container->get(InMemoryRepository::class);

        self::assertSame(1, $calls);
    }

    public function test_a_scope_binding_outranks_a_factory_inherited_from_its_parent(): void
    {
        $container = new Container();
        $container->useCompiledFactories([
            InMemoryRepository::class => static fn (): InMemoryRepository => new InMemoryRepository(),
        ]);

        $scope = $container->createScope();
        $scope->bind(InMemoryRepository::class, DatabaseRepository::class);

        self::assertInstanceOf(DatabaseRepository::class, $scope->get(InMemoryRepository::class));
    }

    /**
     * @param class-string $class
     *
     * @return array<class-string, callable(): object>
     */
    private static function factoriesFor(string $class): array
    {
        $file = tempnam(sys_get_temp_dir(), 'compiled');

        try {
            (new Container())->writeCompiledFactories([$class], $file);

            return Container::loadCompiledFactories($file);
        } finally {
            unlink($file);
        }
    }
}
