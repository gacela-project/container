<?php

declare(strict_types=1);

namespace Gacela\Container;

use Gacela\Container\Attribute\Factory;
use Gacela\Container\Attribute\Lazy;
use Gacela\Container\Attribute\Singleton;
use ReflectionClass;

use function implode;
use function in_array;
use function is_string;
use function var_export;

/**
 * Generates plain `new` expressions for the classes whose construction is
 * fully knowable ahead of time.
 *
 * Deliberately conservative. Anything the generator is not certain about is
 * simply left out and resolves through the normal path at runtime, so the
 * compiled file is an optimisation and never a second, divergent resolver.
 *
 * @psalm-import-type BindingsMap from ContainerInterface
 * @psalm-import-type CompiledPlans from DependencyResolver
 *
 * @internal
 * Not covered by backward compatibility: this class is an implementation
 * detail of Container and may change or disappear in any release
 */
final class ContainerCompiler
{
    /**
     * @param CompiledPlans $plans
     * @param BindingsMap $bindings
     */
    public function __construct(
        private array $plans,
        private array $bindings = [],
    ) {
    }

    /**
     * A `class-string => Closure(): object` map, as PHP source.
     */
    public function render(): string
    {
        $entries = [];

        foreach ($this->plans as $class => $plan) {
            $expression = $this->expressionFor($class, []);

            if ($expression === null) {
                continue;
            }

            $entries[] = '    ' . var_export($class, true) . ' => static fn (): object => ' . $expression . ',';
        }

        return "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n"
            . implode("\n", $entries)
            . "\n];\n";
    }

    /**
     * Classes the generator was able to handle.
     *
     * @return list<class-string>
     */
    public function compilable(): array
    {
        $compilable = [];

        foreach ($this->plans as $class => $plan) {
            if ($this->expressionFor($class, []) !== null) {
                $compilable[] = $class;
            }
        }

        return $compilable;
    }

    /**
     * A `new` expression for $class, or null when anything about it is not
     * statically decidable.
     *
     * @param class-string $class
     * @param list<class-string> $stack guards against cycles
     */
    private function expressionFor(string $class, array $stack): ?string
    {
        if (in_array($class, $stack, true)) {
            return null;
        }

        if (!$this->isEligible($class)) {
            return null;
        }

        $plan = $this->plans[$class] ?? null;
        if ($plan === null || !$plan['instantiable']) {
            return null;
        }

        // A `new` expression cannot assign #[Inject] properties, and doing it
        // in the generated closure would duplicate the resolver.
        if (($plan['props'] ?? []) !== []) {
            return null;
        }

        $arguments = [];
        $stack[] = $class;

        foreach ($plan['params'] as $param) {
            $argument = $this->argumentFor($param, $stack);

            if ($argument === null) {
                return null;
            }

            $arguments[] = $argument;
        }

        return 'new \\' . $class . '(' . implode(', ', $arguments) . ')';
    }

    /**
     * @param array{name: string, hasType: bool, type: string|null, isScalar: bool, inject: class-string|null, hasDefault: bool, default: mixed, declaringClass: string|null} $param
     * @param list<class-string> $stack
     */
    private function argumentFor(array $param, array $stack): ?string
    {
        // #[Inject] resolution is a runtime concern; leave it alone.
        if ($param['inject'] !== null) {
            return null;
        }

        // Scalars and untyped parameters depend on defaults and contextual
        // bindings the generator does not model.
        if (!$param['hasType'] || $param['isScalar'] || !is_string($param['type'])) {
            return null;
        }

        /** @var class-string $type */
        $type = $param['type'];

        // A bound abstract could be rebound after compilation.
        if (isset($this->bindings[$type])) {
            return null;
        }

        return $this->expressionFor($type, $stack);
    }

    /**
     * @param class-string $class
     */
    private function isEligible(string $class): bool
    {
        if (isset($this->bindings[$class]) || !class_exists($class)) {
            return false;
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            return false;
        }

        // Attributes change lifetime or construction, all of which the runtime
        // owns. Never compile them.
        foreach ([Singleton::class, Factory::class, Lazy::class] as $attribute) {
            if ($reflection->getAttributes($attribute) !== []) {
                return false;
            }
        }

        return true;
    }
}
