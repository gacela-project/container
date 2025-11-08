<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\FuzzyMatcher;
use PHPUnit\Framework\TestCase;

use function count;

final class FuzzyMatcherTest extends TestCase
{
    public function test_finds_exact_match(): void
    {
        $candidates = ['UserService', 'OrderService', 'PaymentService'];
        $result = FuzzyMatcher::findSimilar('UserService', $candidates);

        self::assertContains('UserService', $result);
    }

    public function test_finds_similar_names(): void
    {
        $candidates = ['UserService', 'OrderService', 'PaymentService'];
        $result = FuzzyMatcher::findSimilar('UserServce', $candidates); // Typo

        self::assertContains('UserService', $result);
    }

    public function test_finds_interface_vs_class(): void
    {
        $candidates = ['LoggerInterface', 'CacheInterface', 'UserInterface'];
        $result = FuzzyMatcher::findSimilar('LogerInterface', $candidates); // Missing 'g'

        self::assertContains('LoggerInterface', $result);
    }

    public function test_returns_empty_for_no_similar_matches(): void
    {
        $candidates = ['UserService', 'OrderService', 'PaymentService'];
        $result = FuzzyMatcher::findSimilar('CompletelyDifferent', $candidates);

        self::assertEmpty($result);
    }

    public function test_returns_empty_for_empty_candidates(): void
    {
        $result = FuzzyMatcher::findSimilar('UserService', []);

        self::assertEmpty($result);
    }

    public function test_limits_suggestions_to_three(): void
    {
        $candidates = [
            'UserService',
            'UserServiceImpl',
            'UserServiceInterface',
            'UserServiceFactory',
            'UserServiceProvider',
        ];
        $result = FuzzyMatcher::findSimilar('UserService', $candidates);

        self::assertLessThanOrEqual(3, count($result));
    }

    public function test_prioritizes_closer_matches(): void
    {
        $candidates = ['UserService', 'UserServce', 'UserSrvice'];
        $result = FuzzyMatcher::findSimilar('UserService', $candidates);

        // Exact match should be first
        self::assertSame('UserService', $result[0]);
    }

    public function test_handles_namespace_differences(): void
    {
        $candidates = [
            'App\\Service\\LoggerInterface',
            'App\\Service\\CacheInterface',
            'App\\Service\\Logger',
        ];
        $result = FuzzyMatcher::findSimilar('App\\Service\\LogerInterface', $candidates);

        self::assertContains('App\\Service\\LoggerInterface', $result);
    }
}
