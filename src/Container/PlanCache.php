<?php

declare(strict_types=1);

namespace Gacela\Container;

use Gacela\Container\Exception\ContainerException;

use function array_keys;
use function count;
use function time;

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
 * @psalm-import-type CompiledPlans from PlanRegistry
 *
 * @api
 */
final class PlanCache
{
    private readonly PlanRegistry $registry;

    private readonly int $createdAt;

    /**
     * @param CompiledPlans $compiledPlans seeds the cache from a compiled cache
     *   file, so several containers share one read of it instead of one each
     */
    public function __construct(array $compiledPlans = [])
    {
        $this->registry = new PlanRegistry($compiledPlans);
        $this->createdAt = time();
    }

    /**
     * A cache seeded from a file writeTo() wrote, so a new process starts
     * with what an earlier one planned instead of reflecting it again.
     *
     * Entries whose class changed since are dropped, as loadCompiledCache()
     * drops them; pass the $buildStamp used at write time to trust the whole
     * file on one comparison instead. A plan cache only saves reflection, so a
     * file that is missing, unreadable or from another container version gives
     * an empty cache rather than an error: the first request of a deploy, or
     * one after an upgrade, plans by reflection and can write the file again.
     */
    public static function fromFile(string $file, ?string $buildStamp = null): self
    {
        try {
            return new self(CompiledCacheWriter::read($file, $buildStamp));
        } catch (ContainerException) {
            return new self();
        }
    }

    /**
     * Write every plan held so far to $file, in the format fromFile() and
     * loadCompiledCache() read. Nothing is resolved to produce it: this is
     * what the containers sharing this cache have already planned, so calling
     * it at the end of a request persists exactly the classes that request
     * needed. The file is replaced in one rename, so a process reading it
     * meanwhile never sees half of it.
     *
     * A class whose file changed after this cache was created is left out: a
     * process that loaded the old file and lived across a deploy holds a plan
     * of the old constructor, and the stamp taken now would vouch for it.
     *
     * @throws ContainerException when the file cannot be written
     */
    public function writeTo(string $file, ?string $buildStamp = null): void
    {
        CompiledCacheWriter::write($this->registry->plans, $file, $buildStamp, $this->createdAt);
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
