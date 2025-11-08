<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\AliasRegistry;
use PHPUnit\Framework\TestCase;

final class AliasRegistryTest extends TestCase
{
    public function test_resolve_returns_original_when_no_alias(): void
    {
        $registry = new AliasRegistry();

        $result = $registry->resolve('ServiceName');

        self::assertSame('ServiceName', $result);
    }

    public function test_resolve_returns_alias_when_exists(): void
    {
        $registry = new AliasRegistry();
        $registry->add('alias', 'ServiceName');

        $result = $registry->resolve('alias');

        self::assertSame('ServiceName', $result);
    }

    public function test_has_returns_false_when_alias_does_not_exist(): void
    {
        $registry = new AliasRegistry();

        self::assertFalse($registry->has('nonexistent'));
    }

    public function test_has_returns_true_when_alias_exists(): void
    {
        $registry = new AliasRegistry();
        $registry->add('alias', 'ServiceName');

        self::assertTrue($registry->has('alias'));
    }

    public function test_resolve_uses_cache_on_repeated_calls(): void
    {
        $registry = new AliasRegistry();
        $registry->add('alias', 'ServiceName');

        // First call should populate cache
        $result1 = $registry->resolve('alias');
        // Second call should use cache
        $result2 = $registry->resolve('alias');

        self::assertSame('ServiceName', $result1);
        self::assertSame('ServiceName', $result2);
    }

    public function test_adding_new_alias_clears_cache(): void
    {
        $registry = new AliasRegistry();
        $registry->add('alias1', 'ServiceA');

        // Populate cache
        $registry->resolve('alias1');

        // Add new alias should clear cache
        $registry->add('alias2', 'ServiceB');

        // Both should work correctly
        self::assertSame('ServiceA', $registry->resolve('alias1'));
        self::assertSame('ServiceB', $registry->resolve('alias2'));
    }

    public function test_resolve_caches_non_aliased_ids(): void
    {
        $registry = new AliasRegistry();

        // First call
        $result1 = $registry->resolve('DirectService');
        // Second call should use cache
        $result2 = $registry->resolve('DirectService');

        self::assertSame('DirectService', $result1);
        self::assertSame('DirectService', $result2);
    }
}
