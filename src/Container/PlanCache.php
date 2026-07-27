<?php

declare(strict_types=1);

namespace Gacela\Container;

use function array_keys;
use function count;

/**
 * One constructor-plan cache, shared by containers that are not related.
 *
 * A container caches its plans per instance, and `createScope()` shares them
 * down the parent axis. Sibling roots had no such axis: an application that
 * builds one container per module — a common enough shape that a modular
 * framework can end up with dozens of them — re-planned every class the modules
 * had in common, once per container. Hand them all the same PlanCache and the
 * first one to touch a class plans it for the rest.
 *
 * ```php
 * $plans = new PlanCache();
 *
 * $users = new Container($userBindings, [], [], $plans);
 * $orders = new Container($orderBindings, [], [], $plans);
 * ```
 *
 * **What is shared is reflection output and nothing else**: the constructor
 * parameters of a class, whether it is instantiable, and its `#[Inject]`
 * properties. Those are functions of the class, identical whichever container
 * asks. Everything a container was *configured* with stays private to it —
 * bindings, contextual bindings, aliases, tags, singletons, stored instances,
 * `lazy()` registrations and compiled factories. Sharing a plan cache
 * therefore cannot make one container resolve like another; a plan resolved
 * while container A's contextual bindings were in force is not a thing that
 * exists, because a plan does not record how a parameter was satisfied, only
 * what it asks for.
 *
 * One consequence to know, inherited from plans in general: a plan captures
 * each parameter's default *value*, so a `new` in a default is created once
 * and reused. Within a container that was already true; sharing the cache
 * widens it to every container holding this one. Do not use a mutable object
 * as a constructor default if you expect per-instance state — fragile with or
 * without a shared cache.
 *
 * @psalm-import-type CompiledPlans from DependencyResolver
 *
 * @api
 */
final class PlanCache
{
    private readonly PlanRegistry $registry;

    /**
     * @param CompiledPlans $compiledPlans seeds the cache from a compiled cache
     *   file, so several containers share one read of it instead of one each
     */
    public function __construct(array $compiledPlans = [])
    {
        $this->registry = new PlanRegistry($compiledPlans);
    }

    /**
     * How many classes have been planned so far — the number of times
     * reflection did *not* have to run again.
     */
    public function count(): int
    {
        return count($this->registry->plans);
    }

    /**
     * The classes this cache holds a plan for.
     *
     * @return list<class-string>
     */
    public function classes(): array
    {
        return array_keys($this->registry->plans);
    }

    /**
     * @internal the registry is an implementation detail; this exists so a
     * container can take the handle apart, and is not covered by backward
     * compatibility
     */
    public function registry(): PlanRegistry
    {
        return $this->registry;
    }
}
