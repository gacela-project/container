<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\Exception\DependencyInvalidArgumentException;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\ServiceWithScalarDependency;
use PHPUnit\Framework\TestCase;
use Traversable;

use function iterator_to_array;

final class ServiceTaggingTest extends TestCase
{
    public function test_tagged_returns_all_resolved_instances_in_order(): void
    {
        $container = new Container();
        $container->tag([ClassWithoutDependencies::class, Person::class], 'group');

        $items = iterator_to_array($container->tagged('group'));

        self::assertCount(2, $items);
        self::assertInstanceOf(ClassWithoutDependencies::class, $items[0]);
        self::assertInstanceOf(Person::class, $items[1]);
    }

    public function test_tag_appends_ids_across_calls_and_dedupes(): void
    {
        $container = new Container();
        $container->tag([ClassWithoutDependencies::class], 'group');
        $container->tag(Person::class, 'group');
        $container->tag(Person::class, 'group'); // duplicate ignored

        self::assertCount(2, iterator_to_array($container->tagged('group')));
    }

    public function test_unknown_tag_is_empty(): void
    {
        $container = new Container();

        self::assertSame([], iterator_to_array($container->tagged('none')));
    }

    public function test_tagging_is_lazy(): void
    {
        $container = new Container();
        $container->tag([ServiceWithScalarDependency::class], 'lazy');

        // Building the iterable must not resolve anything yet.
        $tagged = $container->tagged('lazy');
        self::assertInstanceOf(Traversable::class, $tagged);

        // Resolution (and its failure) only happens on iteration.
        $this->expectException(DependencyInvalidArgumentException::class);
        iterator_to_array($tagged);
    }
}
