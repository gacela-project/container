<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\ClassSource;
use Gacela\Container\CompilationSkipReason;
use Gacela\Container\Container;
use GacelaTest\Fake\AlternativeCompilableDependency;
use GacelaTest\Fake\CompilableContextualChild;
use GacelaTest\Fake\CompilableContextualParent;
use GacelaTest\Fake\CompilableDependency;
use GacelaTest\Fake\OuterCompilableContextualConsumer;
use GacelaTest\Fake\ServiceWithDefaultedClassDependency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContextualCompilationTest extends TestCase
{
    /**
     * @param class-string<CompilableContextualParent> $consumer
     * @param class-string $context
     */
    #[DataProvider('contextualBindings')]
    public function test_existing_contextual_bindings_keep_runtime_selection(string $consumer, string $context, string $need, bool $null): void
    {
        $value = $null ? null : new AlternativeCompilableDependency();
        $container = new Container();
        $container->when($context)->needs($need)->give($value);
        $source = ClassSource::fromList([$consumer, CompilableDependency::class]);
        $report = $container->compileReport($source);

        self::assertNotContains($consumer, $report->compiled());
        self::assertSame(CompilationSkipReason::ContextualBinding, $report->reasonFor($consumer));
        self::assertStringContainsString($context, (string) $report->explain($consumer));
        self::assertStringContainsString('$dependency', (string) $report->explain($consumer));
        self::assertContains(CompilableDependency::class, $report->compiled());

        $receiver = new Container();
        $receiver->when($context)->needs($need)->give($value);
        self::installFactories($container, $source, $receiver);

        self::assertSame($value, $receiver->get($consumer)->dependency);
    }

    /**
     * @return iterable<string, array{class-string<CompilableContextualParent>, class-string, string, bool}>
     */
    public static function contextualBindings(): iterable
    {
        yield 'root type' => [CompilableContextualParent::class, CompilableContextualParent::class, CompilableDependency::class, false];
        yield 'root name' => [CompilableContextualParent::class, CompilableContextualParent::class, '$dependency', false];
        yield 'inherited actual type' => [CompilableContextualChild::class, CompilableContextualChild::class, CompilableDependency::class, false];
        yield 'inherited actual name' => [CompilableContextualChild::class, CompilableContextualChild::class, '$dependency', false];
        yield 'declaring name fallback' => [CompilableContextualChild::class, CompilableContextualParent::class, '$dependency', false];
        yield 'literal null' => [CompilableContextualChild::class, CompilableContextualChild::class, '$dependency', true];
    }

    #[DataProvider('bindingNeeds')]
    public function test_consumers_of_a_contextual_dependency_are_also_refused(string $need): void
    {
        $container = new Container();
        $container->when(CompilableContextualParent::class)->needs($need)->give(new AlternativeCompilableDependency());
        $source = ClassSource::fromList([OuterCompilableContextualConsumer::class]);
        $report = $container->compileReport($source);

        self::assertSame(CompilationSkipReason::Dependency, $report->reasonFor(OuterCompilableContextualConsumer::class));
        self::assertStringContainsString('contextual', (string) $report->explain(OuterCompilableContextualConsumer::class));

        $receiver = new Container();
        $receiver->when(CompilableContextualParent::class)->needs($need)->give(new AlternativeCompilableDependency());
        self::installFactories($container, $source, $receiver);

        self::assertInstanceOf(AlternativeCompilableDependency::class, $receiver->get(OuterCompilableContextualConsumer::class)->consumer->dependency);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bindingNeeds(): iterable
    {
        yield 'type' => [CompilableDependency::class];
        yield 'name' => ['$dependency'];
    }

    public function test_irrelevant_bindings_do_not_refuse_an_inherited_consumer(): void
    {
        $container = new Container();
        $container->when(CompilableContextualParent::class)->needs(CompilableDependency::class)->give(AlternativeCompilableDependency::class);
        $container->when(CompilableContextualChild::class)->needs('$unused')->give(null);
        $container->when(CompilableDependency::class)->needs('$dependency')->give(null);
        $source = ClassSource::fromList([CompilableContextualChild::class]);

        self::assertContains(CompilableContextualChild::class, $container->compileReport($source)->compiled());
        self::installFactories($container, $source, $container);
        self::assertSame(CompilableDependency::class, $container->get(CompilableContextualChild::class)->dependency::class);
    }

    public function test_describing_contextual_factories_does_not_invoke_them_and_child_null_wins(): void
    {
        $container = new Container();
        $calls = 0;
        $container->when(CompilableContextualParent::class)->needs('$dependency')->give(static function () use (&$calls): AlternativeCompilableDependency {
            ++$calls;
            return new AlternativeCompilableDependency();
        });
        $container->when(CompilableContextualChild::class)->needs('$dependency')->give(null);
        $source = ClassSource::fromList([CompilableContextualChild::class]);

        self::installFactories($container, $source, $container);
        self::assertSame(0, $calls);
        self::assertNull($container->get(CompilableContextualChild::class)->dependency);
        self::assertSame(0, $calls);
    }

    public function test_scope_compilation_preserves_an_inherited_contextual_factory(): void
    {
        $parent = new Container();
        $calls = 0;
        $parent->when(CompilableContextualChild::class)->needs(CompilableDependency::class)->give(static function () use (&$calls): AlternativeCompilableDependency {
            ++$calls;
            return new AlternativeCompilableDependency();
        });
        $scope = $parent->createScope();
        $source = ClassSource::fromList([CompilableContextualChild::class]);

        self::assertSame(CompilationSkipReason::ContextualBinding, $scope->compileReport($source)->reasonFor(CompilableContextualChild::class));
        self::installFactories($scope, $source, $scope);
        self::assertSame(0, $calls);
        self::assertInstanceOf(AlternativeCompilableDependency::class, $scope->get(CompilableContextualChild::class)->dependency);
        self::assertSame(1, $calls);
    }

    public function test_a_named_null_binding_is_reported_before_the_parameter_default(): void
    {
        $container = new Container();
        $container->when(ServiceWithDefaultedClassDependency::class)->needs('$dependency')->give(null);
        $source = ClassSource::fromList([ServiceWithDefaultedClassDependency::class]);

        self::assertSame(CompilationSkipReason::ContextualBinding, $container->compileReport($source)->reasonFor(ServiceWithDefaultedClassDependency::class));
        self::installFactories($container, $source, $container);
        self::assertNull($container->get(ServiceWithDefaultedClassDependency::class)->dependency);
    }

    private static function installFactories(Container $sourceContainer, ClassSource $source, Container $receiver): void
    {
        $file = tempnam(sys_get_temp_dir(), 'contextual-factories');
        try {
            $sourceContainer->writeCompiledFactories($source, $file);
            $receiver->useCompiledFactories(Container::loadCompiledFactories($file));
        } finally {
            unlink($file);
        }
    }
}
