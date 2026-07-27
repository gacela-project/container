<?php

declare(strict_types=1);

namespace Gacela\Container;

/**
 * Holds the constructor plans a resolver has built, so that a scope and the
 * container it came from can share one set.
 *
 * A plan is reflection output keyed by class name, so whichever container
 * builds it first, the whole chain can use the result. Sharing an object rather
 * than copying the array is what makes creating a scope cheap regardless of how
 * much the parent has already resolved.
 *
 * Not quite a pure memo: a plan captures each parameter's default value, so a
 * `new` in an initializer is one object reused by every instantiation. That was
 * already true within a container; sharing widens it to the chain.
 *
 * The map is a public property, not a pair of accessors: reading it sits on
 * the resolver's hot path, once per class of every object graph.
 *
 * @psalm-import-type CompiledPlans from DependencyResolver
 *
 * @internal
 * Not covered by backward compatibility: this class is an implementation
 * detail of Container and may change or disappear in any release
 */
final class PlanRegistry
{
    /**
     * @param CompiledPlans $plans seeded from a compiled cache to skip
     *   reflection at runtime, and populated lazily otherwise
     */
    public function __construct(
        public array $plans = [],
    ) {
    }
}
