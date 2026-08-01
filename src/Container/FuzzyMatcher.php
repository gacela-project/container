<?php

declare(strict_types=1);

namespace Gacela\Container;

use function array_filter;
use function array_map;
use function array_slice;
use function count;
use function levenshtein;
use function max;
use function strlen;
use function usort;

/**
 * Provides fuzzy matching for service names to suggest alternatives.
 *
 * @internal
 * Not covered by backward compatibility: this class is an implementation
 * detail of Container and may change or disappear in any release
 */
final class FuzzyMatcher
{
    private const int MAX_SUGGESTIONS = 3;
    private const float SIMILARITY_THRESHOLD = 0.6;

    /**
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

        usort($scores, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

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
     * The suggestions as the block that goes into an exception message, or an
     * empty string when there are none.
     *
     * Here rather than in each exception: two classes rendered the same list by
     * hand, so the wording a user sees was two literals to keep in step. The
     * matcher that produced the names is where the way they are presented
     * belongs.
     *
     * @param list<string> $suggestions
     */
    public static function renderSuggestions(array $suggestions): string
    {
        if ($suggestions === []) {
            return '';
        }

        $block = "\nDid you mean one of these?\n";

        foreach ($suggestions as $suggestion) {
            $block .= "  - {$suggestion}\n";
        }

        return $block;
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

        return 1.0 - ((float) $distance / (float) $maxLength);
    }
}
