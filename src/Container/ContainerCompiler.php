<?php

declare(strict_types=1);

namespace Gacela\Container;

use Closure;
use Gacela\Container\Attribute\Factory;
use Gacela\Container\Attribute\Lazy;
use Gacela\Container\Attribute\Singleton;
use ReflectionAttribute;
use ReflectionClass;

use function array_key_exists;
use function array_keys;
use function implode;
use function in_array;
use function interface_exists;
use function is_string;
use function sprintf;
use function strlen;
use function var_export;

/**
 * Generates plain `new` expressions for the classes whose construction is
 * fully knowable ahead of time.
 *
 * Deliberately conservative. Anything the generator is not certain about is
 * simply left out and resolves through the normal path at runtime, so the
 * compiled file is an optimisation and never a second, divergent resolver.
 *
 * Each refusal records why, on the branch that makes it, so report() explains
 * the same decision render() acted on rather than re-deriving it.
 *
 * @psalm-import-type ContextualBindingsMap from ContainerInterface
 * @psalm-import-type BindingsMap from ContainerInterface
 * @psalm-import-type CompiledPlans from PlanRegistry
 * @psalm-import-type ParamPlan from PlanRegistry
 *
 * @internal
 * Not covered by backward compatibility: this class is an implementation
 * detail of Container and may change or disappear in any release
 */
final class ContainerCompiler
{
    private const int MAX_INLINE_BYTES = 1024;

    /** @var array<class-string, string> */
    private array $resolvedExpressions = [];

    /** @var array<class-string, true> */
    private array $helpers = [];

    /**
     * Keyed by whatever name was refused, which is not always a class-string:
     * an unbound interface dependency reaches here too.
     *
     * @var array<string, CompilationSkipReason>
     */
    private array $reasons = [];

    /** @var array<string, string> */
    private array $explanations = [];

    /**
     * The verdict, computed once. See expressions().
     *
     * @var array<class-string, string>|null
     */
    private ?array $expressions = null;

    /** @var array<class-string, array<class-string, true>> */
    private array $dependencies = [];

    /**
     * @param CompiledPlans $plans
     * @param BindingsMap $bindings
     * @param array<class-string, true> $lazyClasses classes made lazy through Container::lazy()
     * @param (Closure(string): bool)|null $provides
     * @param ContextualBindingsMap $contextualBindings
     */
    public function __construct(
        private array $plans,
        private array $bindings = [],
        private array $lazyClasses = [],
        private ?Closure $provides = null,
        private array $contextualBindings = [],
    ) {
    }

    /**
     * A `class-string => Closure(): object` map, as PHP source, in the same
     * stamped envelope CompiledCacheWriter writes plans into — a generated
     * `new` expression pins an argument list, so it goes stale exactly as a
     * plan does. Read it back with Container::loadCompiledFactories().
     *
     * @param string|null $buildStamp identifies the build this file belongs to;
     *   see CompiledCacheWriter::read() for what it buys
     */
    public function render(?string $buildStamp = null): string
    {
        $entries = [];
        $stamps = [];

        foreach ($this->expressions() as $class => $expression) {
            $dependencies = array_keys($this->dependencies[$class] ?? []);
            $stamp = CacheStamp::of($class, $dependencies);
            if ($stamp === null) {
                continue;
            }

            $factory = 'static fn (): object => ' . $expression;
            if ($dependencies !== []) {
                $factory = '#[\\' . CompiledFactory::class . '(' . var_export($dependencies, true) . ')] ' . $factory;
            }

            $entries[] = '        ' . var_export($class, true) . ' => ' . $factory . ',';
            $stamps[$class] = $stamp;
        }

        $helpers = [];
        foreach (array_keys($this->helpers) as $class) {
            $helpers[] = '    ' . var_export($class, true) . ' => static fn (array $constructors): object => ' . $this->resolvedExpressions[$class] . ',';
        }
        $setup = $helpers === [] ? '' : '$constructors = [' . "\n" . implode("\n", $helpers) . "\n];\n\n";

        return CompiledCacheWriter::envelope($buildStamp, $stamps, 'factories', $entries, $setup);
    }

    /**
     * Classes the generator was able to handle.
     *
     * @return list<class-string>
     */
    public function compilable(): array
    {
        return array_keys($this->expressions());
    }

    /**
     * The same verdict compilable() reaches, plus the reason behind each refusal.
     */
    public function report(): CompilationReport
    {
        $compilable = $this->compilable();

        // Recursion also judges dependencies that were never asked about; the
        // report answers for the classes the planner described, so that
        // compiled() and skipped() together are exactly the input.
        $reasons = [];
        $explanations = [];

        foreach (array_keys($this->plans) as $class) {
            if (isset($this->reasons[$class])) {
                $reasons[$class] = $this->reasons[$class];
                $explanations[$class] = $this->explanations[$class];
            }
        }

        return new CompilationReport($compilable, $reasons, $explanations);
    }

    /**
     * The `new` expression for every class that has one, keyed by class and in
     * plan order.
     *
     * Both public callers ask the same question of the same immutable inputs —
     * writeCompiledFactories() calls render() and then compilable(), which used
     * to walk the plans and generate every expression twice — so the answer is
     * computed once. Nothing can change it afterwards: the plans, the bindings
     * and the lazy registrations are given at construction and never written,
     * and skip() is first-writer-wins, so a second pass could only re-derive
     * what the first recorded.
     *
     * @return array<class-string, string>
     */
    private function expressions(): array
    {
        if ($this->expressions !== null) {
            return $this->expressions;
        }

        $expressions = [];

        foreach (array_keys($this->plans) as $class) {
            $expression = $this->expressionFor($class, []);

            if ($expression !== null) {
                $expressions[$class] = $expression;
            }
        }

        return $this->expressions = $expressions;
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
            return $this->skip($class, CompilationSkipReason::DependencyCycle, sprintf(
                'it takes part in the dependency cycle %s',
                implode(' -> ', [...$stack, $class]),
            ));
        }

        if (isset($this->resolvedExpressions[$class])) {
            return $this->resolvedExpressions[$class];
        }

        if (isset($this->reasons[$class]) || !$this->isEligible($class)) {
            return null;
        }

        $plan = $this->plans[$class] ?? null;
        if ($plan === null) {
            return $this->skip(
                $class,
                CompilationSkipReason::NoPlan,
                'the planner never described it, so there is nothing to generate from',
            );
        }

        if (!$plan['instantiable']) {
            return $this->skip($class, CompilationSkipReason::NotInstantiable, 'it cannot be instantiated');
        }

        // A `new` expression cannot assign #[Inject] properties, and doing it
        // in the generated closure would duplicate the resolver.
        if (($plan['props'] ?? []) !== []) {
            return $this->skip(
                $class,
                CompilationSkipReason::InjectedProperty,
                'it declares #[Inject] properties, which a `new` expression cannot assign',
            );
        }

        // Same reason as the properties above: the calls are a second thing a
        // `new` expression cannot do, and doing them in the generated closure
        // would make the file a resolver.
        if (($plan['methods'] ?? []) !== []) {
            return $this->skip(
                $class,
                CompilationSkipReason::InjectedMethod,
                'it declares #[Inject] methods, which a `new` expression cannot call',
            );
        }

        $arguments = [];
        $stack[] = $class;

        foreach ($plan['params'] as $param) {
            $argument = $this->argumentFor($class, $param, $stack);

            if ($argument === null) {
                return null;
            }

            $arguments[] = $argument;
        }

        return $this->resolvedExpressions[$class] = 'new \\' . $class . '(' . implode(', ', $arguments) . ')';
    }

    /**
     * @param class-string $class the class whose constructor declares $param
     * @param ParamPlan $param
     * @param list<class-string> $stack
     */
    private function argumentFor(string $class, array $param, array $stack): ?string
    {
        $contexts = [$class];

        if ($param['declaringClass'] !== null && $param['declaringClass'] !== $class) {
            $contexts[] = $param['declaringClass'];
        }

        foreach ($contexts as $context) {
            if (array_key_exists('$' . $param['name'], $this->contextualBindings[$context] ?? [])) {
                return $this->skip($class, CompilationSkipReason::ContextualBinding, sprintf(
                    "parameter \$%s has a named contextual binding for '%s', selected at runtime",
                    $param['name'],
                    $context,
                ));
            }
        }

        if ($param['isVariadic'] ?? false) {
            return $this->skip($class, CompilationSkipReason::VariadicParameter, sprintf(
                'parameter $%s is variadic, so explicit arguments are selected at runtime',
                $param['name'],
            ));
        }

        // #[Inject] resolution is a runtime concern; leave it alone.
        if ($param['inject'] !== null) {
            return $this->skip($class, CompilationSkipReason::InjectedParameter, sprintf(
                'parameter $%s is #[Inject]-annotated, which is resolved at runtime',
                $param['name'],
            ));
        }

        // Scalars and untyped parameters depend on defaults and contextual
        // bindings the generator does not model.
        if (!$param['hasType'] || $param['isScalar'] || !is_string($param['type'])) {
            return $this->skip($class, CompilationSkipReason::ScalarParameter, sprintf(
                'parameter $%s is scalar or untyped, so its value may come from a contextual binding',
                $param['name'],
            ));
        }

        if (isset($this->contextualBindings[$class][$param['type']])) {
            return $this->skip($class, CompilationSkipReason::ContextualBinding, sprintf(
                "parameter \$%s has a contextual type binding for '%s', selected at runtime",
                $param['name'],
                $class,
            ));
        }

        if ($param['hasDefault']) {
            return $this->skip($class, CompilationSkipReason::DefaultParameter, sprintf(
                'parameter $%s has a declared default, which is selected at runtime',
                $param['name'],
            ));
        }

        /** @var class-string $type */
        $type = $param['type'];

        // A bound abstract could be rebound after compilation.
        if (isset($this->bindings[$type])) {
            return $this->skip($class, CompilationSkipReason::Dependency, sprintf(
                "parameter \$%s needs '%s', which is bound and could be rebound after compilation",
                $param['name'],
                $type,
            ));
        }

        $expression = $this->expressionFor($type, $stack);

        if ($expression === null) {
            // Read unconditionally: every path on which expressionFor() returns
            // null goes through skip() for that same class first, so the
            // explanation is already recorded. A `?? 'reason unknown'` here
            // could only ever print itself into a report as a lie.
            return $this->skip($class, CompilationSkipReason::Dependency, sprintf(
                "parameter \$%s needs '%s', which cannot be compiled: %s",
                $param['name'],
                $type,
                $this->explanations[$type],
            ));
        }

        $this->dependencies[$class][$type] = true;
        $this->dependencies[$class] += $this->dependencies[$type] ?? [];

        if (strlen($expression) > self::MAX_INLINE_BYTES) {
            $this->helpers[$type] = true;
            return '$constructors[' . var_export($type, true) . ']($constructors)';
        }

        return $expression;
    }

    /**
     * @param class-string $class
     */
    private function isEligible(string $class): bool
    {
        if (isset($this->bindings[$class]) || ($this->provides !== null && ($this->provides)($class))) {
            $this->skip(
                $class,
                CompilationSkipReason::Bound,
                'it is registered, and the registration could be changed after compilation',
            );
            return false;
        }

        if (isset($this->lazyClasses[$class])) {
            $this->skip(
                $class,
                CompilationSkipReason::LazyRegistration,
                'it is registered with lazy(), and a `new` expression would construct it eagerly',
            );
            return false;
        }

        if (!class_exists($class)) {
            $this->skip(
                $class,
                CompilationSkipReason::NotInstantiable,
                interface_exists($class)
                    ? 'it is an interface with nothing bound to it, so there is no class to write a `new` for'
                    : 'it is not a loadable class',
            );
            return false;
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            $this->skip(
                $class,
                CompilationSkipReason::NotInstantiable,
                'it is an interface, an abstract class, or has a non-public constructor',
            );
            return false;
        }

        // Attributes change lifetime or construction, all of which the runtime
        // owns. Never compile them.
        foreach ([Singleton::class, Factory::class, Lazy::class] as $attribute) {
            if ($reflection->getAttributes($attribute, ReflectionAttribute::IS_INSTANCEOF) !== []) {
                $this->skip($class, CompilationSkipReason::LifetimeAttribute, sprintf(
                    'it carries #[%s], and lifetime belongs to the runtime',
                    (new ReflectionClass($attribute))->getShortName(),
                ));
                return false;
            }
        }

        return true;
    }

    /**
     * Record why $class was refused and hand back the null the caller returns.
     *
     * First writer wins: expressionFor() runs once per caller of render(),
     * compilable() and report(), and the earliest refusal is the specific one.
     */
    private function skip(string $class, CompilationSkipReason $reason, string $explanation): null
    {
        if (!isset($this->reasons[$class])) {
            $this->reasons[$class] = $reason;
            $this->explanations[$class] = $explanation;
        }

        return null;
    }
}
