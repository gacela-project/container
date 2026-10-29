<?php

declare(strict_types=1);

namespace Gacela\Container;

use Attribute;
use Closure;
use ReflectionFunction;

/**
 * @internal
 */
#[Attribute(Attribute::TARGET_FUNCTION)]
final readonly class CompiledFactory
{
    /**
     * @param list<class-string> $dependencies
     */
    public function __construct(public array $dependencies)
    {
    }

    /**
     * @return list<class-string>
     */
    public static function dependenciesOf(mixed $factory): array
    {
        if (!$factory instanceof Closure) {
            return [];
        }

        $attributes = (new ReflectionFunction($factory))->getAttributes(self::class);

        return $attributes === [] ? [] : $attributes[0]->newInstance()->dependencies;
    }
}
