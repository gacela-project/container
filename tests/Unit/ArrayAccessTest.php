<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use GacelaTest\Fake\ClassWithoutDependencies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Stringable;
use TypeError;

final class ArrayAccessTest extends TestCase
{
    public function test_offset_get_maps_to_get(): void
    {
        $container = new Container();

        self::assertInstanceOf(ClassWithoutDependencies::class, $container[ClassWithoutDependencies::class]);
    }

    public function test_offset_set_get_and_exists(): void
    {
        $container = new Container();
        $service = new ClassWithoutDependencies();

        $container['service'] = $service;

        self::assertTrue(isset($container['service']));
        self::assertTrue($container->has('service'));
        self::assertSame($service, $container['service']);
    }

    public function test_offset_unset_maps_to_remove(): void
    {
        $container = new Container();
        $container['service'] = new ClassWithoutDependencies();

        unset($container['service']);

        self::assertFalse(isset($container['service']));
    }

    public function test_offset_exists_is_false_for_unknown_id(): void
    {
        $container = new Container();

        self::assertFalse(isset($container['nothing']));
    }

    #[DataProvider('integerOffsets')]
    public function test_integer_offsets_work_through_the_whole_array_lifecycle(int $offset): void
    {
        $container = new Container();
        $service = new ClassWithoutDependencies();

        self::assertFalse(isset($container[$offset]));
        $container[$offset] = $service;
        self::assertTrue(isset($container[$offset]));
        self::assertSame($service, $container[$offset]);
        self::assertSame($service, $container->get((string) $offset));
        unset($container[$offset]);

        self::assertFalse(isset($container[$offset]));
        self::assertFalse($container->has((string) $offset));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function integerOffsets(): iterable
    {
        yield 'positive' => [1];
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[DataProvider('stringOffsets')]
    public function test_string_ids_keep_their_exact_value(string $offset): void
    {
        $container = new Container();
        $service = new ClassWithoutDependencies();
        $container[$offset] = $service;

        self::assertTrue(isset($container[$offset]));
        self::assertSame($service, $container[$offset]);
        self::assertSame($service, $container->get($offset));
        unset($container[$offset]);

        self::assertFalse(isset($container[$offset]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stringOffsets(): iterable
    {
        yield 'zero' => ['0'];
        yield 'empty' => [''];
        yield 'leading zero' => ['01'];
    }

    public function test_append_syntax_is_rejected_without_overwriting_the_empty_id(): void
    {
        $container = new Container();
        $container[''] = 'keep';

        try {
            $container[] = 'replacement';
            self::fail('An explicit offset is required');
        } catch (TypeError) {
        }

        self::assertSame('keep', $container['']);
    }

    #[DataProvider('invalidOffsetOperations')]
    public function test_other_offset_types_are_rejected_without_coercion(string $operation, mixed $offset): void
    {
        $container = new Container();
        $this->expectException(TypeError::class);

        match ($operation) {
            'set' => $container->offsetSet($offset, 'value'),
            'get' => $container->offsetGet($offset),
            'exists' => $container->offsetExists($offset),
            'unset' => $container->offsetUnset($offset),
        };
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidOffsetOperations(): iterable
    {
        $offsets = [
            'null' => null,
            'boolean' => false,
            'float' => 1.5,
            'array' => ['service'],
            'object' => new stdClass(),
            'stringable' => new class() implements Stringable {
                public function __toString(): string
                {
                    throw new RuntimeException('The offset must not be coerced');
                }
            },
        ];

        foreach ($offsets as $name => $offset) {
            foreach (['set', 'get', 'exists', 'unset'] as $operation) {
                yield $name . ' ' . $operation => [$operation, $offset];
            }
        }
    }
}
