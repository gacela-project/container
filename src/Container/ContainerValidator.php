<?php

declare(strict_types=1);

namespace Gacela\Container;

use function array_keys;
use function class_exists;
use function implode;
use function in_array;
use function interface_exists;
use function sprintf;

/**
 * Proves ahead of time that a class can be resolved, without resolving it.
 *
 * The gap #140 identified: Symfony tells you at compile time that a service is
 * missing because it has a definition set to walk; an autowiring container
 * finds out when a request resolves it. This closes that without importing
 * compiler passes, which have nothing to operate on here — most classes have no
 * definition until they are resolved.
 *
 * Deliberately not a second resolver, for the same reason ContainerCompiler is
 * not one. It answers from two things only: the constructor plans the planner
 * already built, and `has()` on the container itself. `has()` is what makes it
 * honest — it already accounts for bindings, aliases, stored instances,
 * autowirable concretes and parent scopes, so this cannot drift from what
 * resolution would actually do by re-deriving any of it.
 *
 * It therefore reports what is *decidable*: a class that does not exist, an
 * abstract with nothing bound to it, a parameter nothing can supply, a cycle.
 * It does not attempt to predict a closure binding's return value or anything
 * else that only running the code can settle.
 *
 * @psalm-import-type CompiledPlans from DependencyResolver
 * @psalm-import-type ParamPlan from DependencyResolver
 * @psalm-import-type ContextualBindingsMap from ContainerInterface
 *
 * @internal
 * Not covered by backward compatibility: this class is an implementation
 * detail of Container and may change or disappear in any release
 */
final class ContainerValidator
{
    /** @var list<ValidationIssue> */
    private array $issues = [];

    /** @var array<string, true> */
    private array $checked = [];

    /**
     * @param CompiledPlans $plans every class reachable from the roots, already
     *   described — see DependencyResolver::planDeep()
     * @param ContextualBindingsMap $contextualBindings
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly array $plans,
        private readonly array $contextualBindings = [],
    ) {
    }

    /**
     * @param list<class-string> $roots
     */
    public function validate(array $roots): ValidationReport
    {
        foreach ($roots as $root) {
            $this->walk($root, []);
        }

        return new ValidationReport($this->issues, array_keys($this->checked));
    }

    /**
     * @param list<string> $chain how this class was reached
     */
    private function walk(string $class, array $chain): void
    {
        if (in_array($class, $chain, true)) {
            $this->issues[] = new ValidationIssue(
                $class,
                ValidationProblem::DependencyCycle,
                sprintf('it takes part in the cycle %s', implode(' -> ', [...$chain, $class])),
                $chain,
            );

            return;
        }

        // Reached more than once through different parents is normal — a
        // diamond, not a problem. Only walk it once.
        if (isset($this->checked[$class])) {
            return;
        }

        $this->checked[$class] = true;

        if (!class_exists($class)) {
            $this->issues[] = new ValidationIssue(
                $class,
                ValidationProblem::MissingClass,
                interface_exists($class)
                    ? 'it is an interface and nothing is bound to it'
                    : 'it does not exist, or could not be autoloaded',
                $chain,
            );

            return;
        }

        $plan = $this->plans[$class] ?? null;

        if ($plan === null) {
            return;
        }

        if (!$plan['instantiable']) {
            $this->issues[] = new ValidationIssue(
                $class,
                ValidationProblem::NotInstantiable,
                'it is abstract or its constructor is not public, and nothing is bound to it',
                $chain,
            );

            return;
        }

        $chain[] = $class;

        foreach ($plan['params'] as $param) {
            $this->checkParameter($class, $param, $chain);
        }
    }

    /**
     * @param ParamPlan $param
     * @param list<string> $chain
     */
    private function checkParameter(string $class, array $param, array $chain): void
    {
        // A named contextual binding supplies the parameter whatever its type,
        // so it settles the question before anything else is asked.
        if ($this->hasNamedBinding($param)) {
            return;
        }

        if ($param['inject'] !== null) {
            $this->descend($param['inject'], $chain);

            return;
        }

        if (!$param['hasType']) {
            $this->unresolvable($class, $param['name'], 'it has no type to resolve from', $chain);

            return;
        }

        if ($param['isScalar']) {
            if (!$param['hasDefault']) {
                $this->unresolvable(
                    $class,
                    $param['name'],
                    sprintf('it is %s with no default and no contextual binding', (string) $param['type']),
                    $chain,
                );
            }

            return;
        }

        $type = $param['type'];

        if ($type === null) {
            // A union or intersection type. Resolution cannot pick one either,
            // so it is only fine when a default covers it.
            if (!$param['hasDefault']) {
                $this->unresolvable($class, $param['name'], 'its type is not a single class', $chain);
            }

            return;
        }

        $this->descend($type, $chain);
    }

    /**
     * @param list<string> $chain
     */
    private function descend(string $type, array $chain): void
    {
        // The container is the authority on whether an id resolves: it knows
        // the bindings, aliases, instances and parent scopes this class does
        // not, and asking it is what keeps this from becoming a second
        // resolver. A bound abstract is satisfied here and goes no deeper,
        // since what it binds *to* is the container's business.
        if (!class_exists($type) && $this->container->has($type)) {
            return;
        }

        $this->walk($type, $chain);
    }

    /**
     * @param list<string> $chain
     */
    private function unresolvable(string $class, string $name, string $why, array $chain): void
    {
        $this->issues[] = new ValidationIssue(
            $class,
            ValidationProblem::UnresolvableParameter,
            sprintf('parameter $%s cannot be supplied: %s', $name, $why),
            $chain,
        );
    }

    /**
     * @param ParamPlan $param
     */
    private function hasNamedBinding(array $param): bool
    {
        $declaringClass = $param['declaringClass'];

        return $declaringClass !== null
            && isset($this->contextualBindings[$declaringClass]['$' . $param['name']]);
    }
}
