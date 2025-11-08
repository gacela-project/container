<?php

declare(strict_types=1);

namespace Gacela\Container;

use function array_filter;
use function array_map;
use function array_slice;
use function count;
use function levenshtein;
use function max;
use function min;
use function strlen;
use function usort;

/**
 * Provides fuzzy matching for service names to suggest alternatives.
 */
final class FuzzyMatcher
{
    private const MAX_SUGGESTIONS = 3;
    private const SIMILARITY_THRESHOLD = 0.6;

    /**
     * Find similar strings from a list of candidates.
     *
     * @param list<string> $candidates
     *
     * @return list<string>
     */
    public static function findSimilar(string $target, array $candidates): array
    {
        if (count($candidates) === 0) {
            return [];
        }

        $scores = array_map(
            static fn (string $candidate): array => [
                'name' => $candidate,
                'score' => self::calculateSimilarity($target, $candidate),
            ],
            $candidates,
        );

        // Sort by score descending
        usort($scores, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        // Filter by threshold and limit results
        $filtered = array_filter(
            $scores,
            static fn (array $item): bool => $item['score'] >= self::SIMILARITY_THRESHOLD,
        );

        $suggestions = array_map(
            static fn (array $item): string => $item['name'],
            $filtered,
        );

        return array_slice($suggestions, 0, self::MAX_SUGGESTIONS);
    }

    /**
     * Calculate similarity between two strings (0.0 to 1.0).
     */
    private static function calculateSimilarity(string $a, string $b): float
    {
        $maxLength = max(strlen($a), strlen($b));
        if ($maxLength === 0) {
            return 1.0;
        }

        $distance = levenshtein($a, $b);
        if ($distance === -1) {
            // Strings too long for levenshtein, use simple approach
            return self::simpleSimilarity($a, $b);
        }

        return 1.0 - ($distance / $maxLength);
    }

    /**
     * Fallback similarity calculation for very long strings.
     */
    private static function simpleSimilarity(string $a, string $b): float
    {
        $maxLength = max(strlen($a), strlen($b));
        $minLength = min(strlen($a), strlen($b));

        if ($maxLength === 0) {
            return 1.0;
        }

        // Simple length-based similarity
        return $minLength / $maxLength;
    }
}
