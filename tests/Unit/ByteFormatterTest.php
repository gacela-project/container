<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\ByteFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Byte formatting used to live as a private method on Container, reachable
 * only through getStats()['memory_usage'] — whose input is live process
 * memory. That made it pure, deterministic logic behind a non-deterministic
 * input, so every arithmetic mutation survived.
 */
final class ByteFormatterTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function bytesProvider(): iterable
    {
        yield 'zero' => [0, '0 B'];
        yield 'one byte' => [1, '1 B'];
        yield 'just under a kilobyte' => [1023, '1023 B'];
        yield 'exactly a kilobyte' => [1024, '1 KB'];
        yield 'fractional kilobytes' => [1536, '1.5 KB'];
        yield 'exactly a megabyte' => [1048576, '1 MB'];
        yield 'exactly a gigabyte' => [1073741824, '1 GB'];
        // Beyond the largest unit the exponent clamps rather than running off
        // the end of the units list.
        yield 'a terabyte clamps to GB' => [1099511627776, '1024 GB'];
        yield 'negative clamps to zero' => [-1, '0 B'];
    }

    #[DataProvider('bytesProvider')]
    public function test_it_formats(int $bytes, string $expected): void
    {
        self::assertSame($expected, ByteFormatter::format($bytes));
    }

    public function test_rounding_keeps_two_decimals(): void
    {
        // 1024 + 100 bytes = 1.09765625 KB
        self::assertSame('1.1 KB', ByteFormatter::format(1124));
    }

    public function test_it_is_used_by_the_container_stats(): void
    {
        $stats = (new \Gacela\Container\Container())->getStats();

        self::assertMatchesRegularExpression('/^\d+(\.\d+)? (B|KB|MB|GB)$/', $stats['memory_usage']);
    }
}
