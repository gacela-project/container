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
 * The shape of a plan is declared here rather than on DependencyResolver, which
 * builds them: seven classes need this vocabulary and only one of them resolves
 * anything, so hanging it off the resolver made every reader of a plan point
 * back at it — including this registry, which the resolver constructs. Owned by
 * the type that holds the data, the vocabulary flows one way.
 *
 * @psalm-type ParamPlan = array{name: string, hasType: bool, type: string|null, isScalar: bool, inject: class-string|null, hasDefault: bool, default: mixed, declaringClass: string|null}
 * @psalm-type PropPlan = array{name: string, hasType: bool, type: string|null, isScalar: bool, inject: class-string|null, isReadonly: bool, declaringClass: class-string}
 * @psalm-type MethodPlan = array{name: string, params: list<ParamPlan>, isStatic: bool, isPublic: bool, declaringClass: class-string}
 * @psalm-type ClassPlan = array{instantiable: bool, params: list<ParamPlan>, props: list<PropPlan>, methods: list<MethodPlan>}
 * @psalm-type StoredClassPlan = array{instantiable: bool, params: list<ParamPlan>, props?: list<PropPlan>, methods?: list<MethodPlan>}
 * @psalm-type CompiledPlans = array<class-string, StoredClassPlan>
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
