<?php

declare(strict_types=1);

namespace Gacela\Container;

use function in_array;
use function is_array;

/**
 * Groups service identifiers under tags so they can be resolved together.
 *
 * @internal
 * Not covered by backward compatibility: this class is an implementation
 * detail of Container and may change or disappear in any release
 */
final class TagRegistry
{
    /** @var array<string, list<string>> */
    private array $tags = [];

    /**
     * @param string|list<string> $ids
     */
    public function tag(string|array $ids, string $tag): void
    {
        $ids = is_array($ids) ? $ids : [$ids];

        foreach ($ids as $id) {
            if (!isset($this->tags[$tag])) {
                $this->tags[$tag] = [];
            }

            if (!in_array($id, $this->tags[$tag], true)) {
                $this->tags[$tag][] = $id;
            }
        }
    }

    /**
     * @return list<string>
     */
    public function idsFor(string $tag): array
    {
        return $this->tags[$tag] ?? [];
    }
}
