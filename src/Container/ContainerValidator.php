<?php

declare(strict_types=1);

namespace Gacela\Container;

use Closure;
use Gacela\Container\Exception\ContainerException;

use function array_keys;
use function class_exists;
use function implode;
use function in_array;
use function interface_exists;
use function sprintf;

/**
 * @psalm-import-type StoredClassPlan from PlanRegistry
 * @psalm-import-type ParamPlan from PlanRegistry
 * @psalm-import-type PropPlan from PlanRegistry
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

    /** @var array<string, true> */
    private array $inspected = [];

    /**
     * @param Closure(class-string): (StoredClassPlan|null) $planFor
     * @param Closure(string): (string|null) $targetFor
     * @param ContextualBindingsMap $contextualBindings
     */
    public function __construct(
        private readonly Closure $planFor,
        private readonly Closure $targetFor,
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
    private function walk(string $class, array $chain, bool $useRegistration = true): void
    {
        $target = $class;
        if ($useRegistration) {
            try {
                $target = ($this->targetFor)($class);
            } catch (ContainerException $exception) {
                $this->issues[] = new ValidationIssue($class, ValidationProblem::DependencyCycle, $exception->getMessage(), $chain);
                return;
            }
            if ($target === null) {
                $this->checked[$class] = true;
                return;
            }
        }

        if (in_array($class, $chain, true)) {
            $this->issues[] = new ValidationIssue(
                $class,
                ValidationProblem::DependencyCycle,
                sprintf('it takes part in the cycle %s', implode(' -> ', [...$chain, $class])),
                $chain,
            );

            return;
        }

        $inspection = ($useRegistration ? 'registered:' : 'constructor:') . $class;
        if (isset($this->inspected[$inspection])) {
            return;
        }

        $this->checked[$class] = true;
        $this->inspected[$inspection] = true;
        if ($target !== $class) {
            $this->walk($target, [...$chain, $class], !class_exists($target));
            return;
        }

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

        $plan = ($this->planFor)($class);

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

        foreach ($plan['props'] ?? [] as $prop) {
            $this->checkProperty($class, $prop, $chain);
        }

        foreach ($plan['methods'] ?? [] as $method) {
            if ($method['isStatic'] || !$method['isPublic']) {
                $this->invalidInjection($class, sprintf('method %s() must be public and non-static', $method['name']), $chain);
                continue;
            }
            foreach ($method['params'] as $param) {
                $this->checkParameter($class, $param, $chain);
            }
        }
    }

    /**
     * @param PropPlan $prop
     * @param list<string> $chain
     */
    private function checkProperty(string $class, array $prop, array $chain): void
    {
        if ($prop['isReadonly']) {
            $this->invalidInjection($class, sprintf('property $%s is readonly', $prop['name']), $chain);
            return;
        }

        if ($prop['inject'] !== null) {
            $this->walk($prop['inject'], $chain);
            return;
        }

        if (!$prop['hasType'] || $prop['isScalar'] || $prop['type'] === null) {
            $this->invalidInjection($class, sprintf('property $%s needs a class type or an explicit injection target', $prop['name']), $chain);
            return;
        }

        $this->walk($prop['type'], $chain);
    }

    /**
     * @param list<string> $chain
     */
    private function invalidInjection(string $class, string $why, array $chain): void
    {
        $this->issues[] = new ValidationIssue($class, ValidationProblem::InvalidInjection, $why, $chain);
    }

    /**
     * @param ParamPlan $param
     * @param list<string> $chain
     */
    private function checkParameter(string $class, array $param, array $chain): void
    {
        if (($param['isVariadic'] ?? false) && $param['inject'] === null) {
            return;
        }

        if ($this->hasNamedBinding($param)) {
            return;
        }

        if ($param['inject'] !== null) {
            $this->walk($param['inject'], $chain);

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
