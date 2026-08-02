<?php

declare(strict_types=1);

namespace Gacela\Container;

use function in_array;
use function is_array;
use function is_int;

/**
 * Groups service identifiers under tags so they can be resolved together, or
 * one at a time by key.
 *
 * A tag is one ordered map, not a list plus a map: an entry registered with a
 * key is addressable by it, an entry registered without one is not, and both
 * iterate in the order they were added. Keeping them in a single structure is
 * what lets `['email' => …]` be added to a tag that already holds plain ids
 * without either kind having to know about the other.
 *
 * @internal
 * Not covered by backward compatibility: this class is an implementation
 * detail of Container and may change or disappear in any release
 */
final class TagRegistry
{
    /** @var array<string, array<array-key, string>> */
    private array $tags = [];

    /**
     * A string key addresses the entry and replaces whatever was under it —
     * which is what makes per-environment layering work. An id added without a
     * key is appended, and is skipped when the tag already holds it, so
     * repeated registration of the same handler stays one entry.
     *
     * @param string|array<array-key, string> $ids
     */
    public function tag(string|array $ids, string $tag): void
    {
        $this->tags[$tag] = self::merge($this->tags[$tag] ?? [], is_array($ids) ? $ids : [$ids]);
    }

    /**
     * @return array<array-key, string>
     */
    public function idsFor(string $tag): array
    {
        return $this->tags[$tag] ?? [];
    }

    /**
     * The merge rule itself, kept in one place because it is applied twice: on
     * registration here, and by Container::taggedIds() layering a scope's tags
     * over the ones it inherits. Two copies of "a key replaces, an id appends"
     * is two things to keep in step.
     *
     * @param array<array-key, string> $into
     * @param array<array-key, string> $ids
     *
     * @return array<array-key, string>
     */
    public static function merge(array $into, array $ids): array
    {
        foreach ($ids as $key => $id) {
            if (!is_int($key)) {
                $into[$key] = $id;

                continue;
            }

            if (!in_array($id, $into, true)) {
                $into[] = $id;
            }
        }

        return $into;
    }
}
