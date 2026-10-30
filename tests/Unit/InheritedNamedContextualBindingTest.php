<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use DateTimeImmutable;
use Gacela\Container\Container;
use Gacela\Container\ContainerInterface;
use GacelaTest\Fake\ChildInheritingAnInjectedMethod;
use GacelaTest\Fake\ChildWithNamedParameters;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\ParentDeclaringAnInjectedMethod;
use GacelaTest\Fake\ParentWithNamedParameters;
use GacelaTest\Fake\SiblingWithNamedParameters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function count;

final class InheritedNamedContextualBindingTest extends TestCase
{
    public function test_a_named_binding_supplies_an_inherited_required_constructor_parameter(): void
    {
        $container = new Container();
        $container->when(ChildWithNamedParameters::class)->needs('$apiKey')->give('child-key');

        $service = $container->get(ChildWithNamedParameters::class);

        self::assertSame('child-key', $service->apiKey);
        self::assertSame('constructor default', $service->value);
        self::assertSame('setter default', $service->setterValue);
    }

    public function test_the_actual_consumer_wins_and_siblings_keep_declaring_class_fallbacks(): void
    {
        $container = self::configuredParent();
        $container->when(ChildWithNamedParameters::class)->needs('$apiKey')->give('child-key');
        $container->when(ChildWithNamedParameters::class)->needs('$value')->give('child value');
        $container->when(ChildWithNamedParameters::class)->needs('$setterValue')->give('child setter');

        $child = $container->get(ChildWithNamedParameters::class);
        $sibling = $container->get(SiblingWithNamedParameters::class);
        $parent = $container->get(ParentWithNamedParameters::class);

        self::assertSame('child-key', $child->apiKey);
        self::assertSame('child value', $child->value);
        self::assertSame('child setter', $child->setterValue);
        self::assertSame('parent-key', $sibling->apiKey);
        self::assertSame('parent value', $sibling->value);
        self::assertSame('parent setter', $sibling->setterValue);
        self::assertSame('parent value', $parent->value);
    }

    public function test_a_named_binding_supplies_an_inherited_typed_inject_setter(): void
    {
        $container = new Container();
        $parent = new ClassWithoutDependencies();
        $child = new ClassWithoutDependencies();
        $container->when(ParentDeclaringAnInjectedMethod::class)->needs('$dependency')->give($parent);
        $container->when(ChildInheritingAnInjectedMethod::class)->needs('$dependency')->give($child);

        self::assertSame($child, $container->get(ChildInheritingAnInjectedMethod::class)->fromParent);
        self::assertSame($parent, $container->get(ParentDeclaringAnInjectedMethod::class)->fromParent);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function literalValues(): iterable
    {
        yield 'null' => [null];
        yield 'callable string' => ['date'];
        yield 'callable array' => [[DateTimeImmutable::class, 'createFromFormat']];
        yield 'invokable object' => [new class() {
            public function __invoke(): string
            {
                return 'invoked';
            }
        }];
        yield 'plain object' => [new ClassWithoutDependencies()];
    }

    #[DataProvider('literalValues')]
    public function test_more_specific_literal_values_override_constructor_and_setter_fallbacks(mixed $value): void
    {
        $container = self::configuredParent();
        $container->when(ChildWithNamedParameters::class)->needs('$value')->give($value);
        $container->when(ChildWithNamedParameters::class)->needs('$setterValue')->give($value);

        $child = $container->get(ChildWithNamedParameters::class);

        self::assertSame('parent-key', $child->apiKey);
        self::assertSame($value, $child->value);
        self::assertSame($value, $child->setterValue);
    }

    public function test_nested_constructor_and_setter_factories_receive_the_current_container(): void
    {
        $container = self::configuredParent();
        $calls = [];
        $factory = static function (ContainerInterface $current) use (&$calls): string {
            $calls[] = $current;

            return 'value-' . count($calls);
        };
        $container->when(ChildWithNamedParameters::class)->needs('$value')->give($factory);
        $container->when(ChildWithNamedParameters::class)->needs('$setterValue')->give($factory);

        $child = $container->resolve(static fn (ChildWithNamedParameters $child): ChildWithNamedParameters => $child);

        self::assertSame('value-1', $child->value);
        self::assertSame('value-2', $child->setterValue);
        self::assertSame([$container, $container], $calls);
    }

    public function test_late_parent_configuration_reaches_scopes_without_replacing_local_named_bindings(): void
    {
        $container = self::configuredParent();
        $scope = $container->createScope();
        self::assertSame('parent value', $scope->get(ChildWithNamedParameters::class)->value);
        $scope->when(ChildWithNamedParameters::class)->needs('$value')->give('scope value');
        $container->when(ChildWithNamedParameters::class)->needs('$apiKey')->give('late-key');
        $container->when(ChildWithNamedParameters::class)->needs('$value')->give('root child value');
        $container->when(ChildWithNamedParameters::class)->needs('$setterValue')->give('late setter');

        $scoped = $scope->get(ChildWithNamedParameters::class);
        $root = $container->get(ChildWithNamedParameters::class);

        self::assertSame('scope value', $scoped->value);
        self::assertSame('late-key', $scoped->apiKey);
        self::assertSame('late setter', $scoped->setterValue);
        self::assertSame('root child value', $root->value);
        self::assertSame('parent value', $scope->get(SiblingWithNamedParameters::class)->value);
    }

    public function test_factory_frames_use_the_context_that_actually_matched(): void
    {
        $container = self::configuredParent();
        $calls = 0;
        $factory = static function (ContainerInterface $current) use (&$calls): string {
            if (++$calls === 1) {
                $value = $current->get(SiblingWithNamedParameters::class)->value;
                self::assertIsString($value);

                return $value;
            }

            return 'resolved';
        };
        $container->when(ParentWithNamedParameters::class)->needs('$value')->give($factory);
        $container->when(ChildWithNamedParameters::class)->needs('$value')->give($factory);

        self::assertSame('resolved', $container->get(ChildWithNamedParameters::class)->value);
        self::assertSame(2, $calls);
    }

    public function test_callable_parameters_do_not_inherit_the_outer_constructors_named_binding(): void
    {
        $container = self::configuredParent();
        $container->when(ChildWithNamedParameters::class)->needs('$value')->give(
            static fn (ContainerInterface $current): mixed => $current->resolve([self::class, 'callableValue']),
        );

        self::assertSame('callable default', $container->get(ChildWithNamedParameters::class)->value);
    }

    public static function callableValue(mixed $value = 'callable default'): mixed
    {
        return $value;
    }

    public function test_explicit_lazy_construction_uses_the_inherited_consumer_bindings(): void
    {
        $container = self::configuredParent();
        $container->when(ChildWithNamedParameters::class)->needs('$apiKey')->give('lazy-key');
        $container->when(ChildWithNamedParameters::class)->needs('$setterValue')->give('lazy setter');
        $container->lazy(ChildWithNamedParameters::class);
        $child = $container->get(ChildWithNamedParameters::class);

        self::assertSame('lazy-key', $child->apiKey);
        self::assertSame('lazy setter', $child->setterValue);
    }

    private static function configuredParent(): Container
    {
        $container = new Container();
        $container->when(ParentWithNamedParameters::class)->needs('$apiKey')->give('parent-key');
        $container->when(ParentWithNamedParameters::class)->needs('$value')->give('parent value');
        $container->when(ParentWithNamedParameters::class)->needs('$setterValue')->give('parent setter');

        return $container;
    }
}
