<?php

declare(strict_types=1);

namespace Gacela\Container;

use Closure;
use Gacela\Container\Attribute\Inject;
use Gacela\Container\Attribute\Lazy;
use Gacela\Container\Exception\CircularDependencyException;
use Gacela\Container\Exception\DependencyInvalidArgumentException;
use Gacela\Container\Exception\DependencyNotFoundException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use Throwable;
use WeakMap;
use WeakReference;

use function array_key_exists;
use function array_keys;
use function class_exists;
use function count;
use function is_callable;
use function is_object;
use function is_string;
use function method_exists;

/**
 * @psalm-import-type BindingsMap from ContainerInterface
 * @psalm-import-type ContextualBindingsMap from ContainerInterface
 * @psalm-import-type ParamPlan from PlanRegistry
 * @psalm-import-type PropPlan from PlanRegistry
 * @psalm-import-type MethodPlan from PlanRegistry
 * @psalm-import-type ClassPlan from PlanRegistry
 * @psalm-import-type StoredClassPlan from PlanRegistry
 * @psalm-import-type CompiledPlans from PlanRegistry
 *
 * @internal
 * Not covered by backward compatibility: this class is an implementation
 * detail of Container and may change or disappear in any release
 */
final class DependencyResolver
{
    /**
     * Per-class constructors that bypass the resolution path; false for a class
     * proven ineligible, true for one constructed once and not yet worth
     * composing. See argBuilderFor().
     *
     * Public for the same reason PlanRegistry::$plans is: DependencyCacheManager
     * reads it once per construction, and going through a method to learn an
     * answer already recorded here costs more than the lookup does.
     *
     * A Closure in here is always safe to call: everything that could change
     * what a class resolves to — a binding, an alias, a stored instance, a
     * contextual binding, lazy(), becoming a scope — drops the whole map first.
     *
     * @var array<class-string, (Closure(): object)|bool>
     */
    public array $argBuilders = [];

    /** @var array<class-string, bool> */
    private array $resolvingStack = [];

    /** @var array<class-string, true> guards the recursion in composeBuilder() */
    private array $buildingBuilders = [];

    /** @var array<string, bool> Memoized class_exists()/interface_exists() checks */
    private array $typeExistsCache = [];

    /** @var list<class-string> */
    private array $buildStack = [];

    /**
     * Property plans keyed by class, shared across containers.
     *
     * A class definition cannot change within a process, so this is a pure memo
     * of reflection output. Sharing it keeps the scan off the cold-start path:
     * without it every new container would re-reflect every class it touches,
     * taxing users who have no #[Inject] properties at all.
     *
     * @var array<class-string, list<PropPlan>>
     */
    private static array $propertyPlans = [];

    /**
     * The #[Inject] methods of a class, memoized process-wide for the same
     * reason the property plans are: it is reflection output keyed on a class
     * definition, which cannot change within a process.
     *
     * @var array<class-string, list<MethodPlan>>
     */
    private static array $methodPlans = [];

    /**
     * Whether a class carries #[Lazy], memoized process-wide.
     *
     * Read off a class definition, which cannot change within a process, so it
     * is shared for the same reason the property plans are. Deliberately not
     * part of the class plan: a #[Lazy] class must be answered *without*
     * describing its constructor, which is the work laziness exists to defer.
     *
     * @var array<class-string, bool>
     */
    private static array $lazyAttribute = [];

    /** @var array<string, ReflectionProperty> */
    private array $propertyHandles = [];

    /**
     * Parameter plans of callables that have a name to key on: a function, a
     * `Class::method`, an invokable's `__invoke`.
     *
     * @var array<string, list<ParamPlan>>
     */
    private array $callablePlans = [];

    /**
     * The same for closures, which have none. Built on first use, since most
     * containers never resolve one.
     *
     * @var WeakMap<Closure, list<ParamPlan>>|null
     */
    private ?WeakMap $closurePlans = null;

    /**
     * The container a scope falls through to. Typed as the interface rather
     * than Container: only provides(), get() and getBindings() are ever asked
     * of it, all three are on the contract, and naming the concrete class here
     * pointed the resolver back at the class that owns it for no gain.
     */
    private ?ContainerInterface $parent = null;

    /**
     * Hoisted out of $parent so the fall-through test costs a bool read on the
     * hot path. Every unbound constructor parameter of every graph reaches it,
     * and for a container with no parent — nearly all of them — the answer is
     * fixed for the resolver's whole life.
     */
    private bool $hasParent = false;

    /**
     * Whether the runtime can build native lazy objects (PHP 8.4+). On older
     * runtimes lazy targets are constructed eagerly, which is unobservable
     * apart from the timing.
     *
     * Hoisted into a field for the same reason $hasParent is: every nested
     * parameter of every graph tests it.
     */
    private bool $supportsLazyObjects;

    private static ?bool $lazyObjectsAvailable = null;

    /**
     * @param BindingsMap $bindings
     * @param ContextualBindingsMap $contextualBindings
     * @param WeakReference<ContainerInterface>|null $containerRef weak on purpose; see Container::__construct()
     * @param array<class-string, true> $lazyClasses classes made lazy through Container::lazy()
     * @param array<class-string, Closure> $lazyFactories lazy targets whose instance a closure produces
     */
    public function __construct(
        private array &$bindings = [],
        private array &$contextualBindings = [],
        private PlanRegistry $planRegistry = new PlanRegistry(),
        private ?WeakReference $containerRef = null,
        private array &$lazyClasses = [],
        private array &$lazyFactories = [],
    ) {
        $this->supportsLazyObjects = self::$lazyObjectsAvailable
            ??= method_exists(ReflectionClass::class, 'newLazyGhost');
    }

    /**
     * Drop the caches that outlive every container.
     *
     * $propertyPlans and $lazyAttribute are memos of a class definition, and
     * are the reason this exists. $lazyObjectsAvailable is a fact about the
     * running PHP binary, which no reset can change — it is cleared anyway so
     * that "every static is back at its declared default" holds without
     * exceptions, and the next resolver recomputes the same answer.
     *
     * See Container::resetStaticCaches(), the supported way in.
     */
    public static function resetCache(): void
    {
        self::$propertyPlans = [];
        self::$methodPlans = [];
        self::$lazyAttribute = [];
        self::$lazyObjectsAvailable = null;
    }

    /**
     * Change what a closure binding, a contextual closure or a lazy factory is
     * handed. See Container::withSelfReference().
     *
     * @param WeakReference<ContainerInterface> $containerRef
     */
    public function useSelfReference(WeakReference $containerRef): void
    {
        $this->containerRef = $containerRef;
    }

    /**
     * Let a scope hand unresolved types to the container it was created from.
     */
    public function inheritFrom(ContainerInterface $parent): void
    {
        $this->parent = $parent;
        $this->hasParent = true;

        // A scope resolves through its parent, which eligibleForBuilders()
        // refuses — and the inline read in DependencyCacheManager::construct()
        // trusts a stored Closure without re-testing eligibility. Today this is
        // called before the resolver has built anything; dropping here makes
        // that a property of the code rather than of the call order.
        $this->argBuilders = [];
    }

    /**
     * @param class-string $toResolve
     * @param array<string, mixed> $overrides runtime values keyed by parameter name (top level only)
     *
     * @return list<mixed>
     */
    public function resolveDependencies(string $toResolve, array $overrides = []): array
    {
        // Track which class is being resolved for contextual bindings.
        $this->buildStack[] = $toResolve;

        try {
            return $this->resolveEntryParameters($this->describeClass($toResolve)['params'], $overrides);
        } finally {
            array_pop($this->buildStack);
        }
    }

    /**
     * @param array<string, mixed> $overrides runtime values keyed by parameter name
     *
     * @return list<mixed>
     */
    public function resolveCallableDependencies(callable $callable, array $overrides = []): array
    {
        return $this->resolveEntryParameters($this->describeCallable($callable), $overrides);
    }

    /**
     * Whether $className can be instantiated at all.
     *
     * Answered off the class plan, which resolveDependencies() builds a moment
     * later anyway. Reflecting separately for this made every cold get() pay
     * for two ReflectionClass instances where one already had the answer.
     *
     * @param class-string $className
     */
    public function isInstantiable(string $className): bool
    {
        return class_exists($className) && $this->describeClass($className)['instantiable'];
    }

    /**
     * A closure that constructs $className and its whole subtree with plain
     * `new`, or null when anything about the graph is not statically knowable.
     *
     * The resolution path is a dozen calls deep before the first `new` runs,
     * and it recurses per node — so a four-level graph pays it four times over.
     * Generated factories beat a warm resolve by a wide margin with the same
     * reflection already cached, which says the remaining cost is dispatch
     * rather than work. This is that saving without a build step.
     *
     * It is only ever installed when the answer cannot depend on configuration,
     * and the guard is deliberately structural rather than a set of memos to
     * invalidate. Everything it reads is either a fact about a class
     * definition, which cannot change within a process, or one of the arrays
     * checked in eligibleForBuilders() on every call — so registering anything
     * takes the whole mechanism out of play at once, and there is no
     * "remember to drop the memo" for a future change to get wrong.
     *
     * Not mutation-tested, and the reason is the design rather than a gap: every
     * branch here is fail-safe, so mutating one does not produce a wrong object,
     * it produces a *missed optimisation*. Return null where a builder was due
     * and the container falls back to the resolution path and returns the
     * identical result — there is nothing for a test to observe. The branches
     * that *are* observable, the refusals in composeBuilder(), stay covered:
     * deleting them fails ten tests.
     *
     * @infection-ignore-all
     *
     * @param class-string $className
     *
     * @return (Closure(): object)|null
     */
    public function argBuilderFor(string $className): ?Closure
    {
        $cached = $this->argBuilders[$className] ?? null;

        // The refusal is checked first and without the container gate, because
        // it is the common case and it can never become wrong: every reason a
        // class is refused is a fact about its own declaration, and falling
        // back to the resolution path is always correct anyway. A class that
        // *is* eligible still has the gate applied below.
        if ($cached === false) {
            return null;
        }

        if (!$this->eligibleForBuilders()) {
            // Recorded as a refusal rather than re-derived per construction:
            // for a given resolver this answer cannot go back to true. A parent
            // is never removed, and the contextual and lazy maps only grow —
            // every registration that adds to one drops this whole map first,
            // so a stale false cannot outlive the state that produced it.
            $this->argBuilders[$className] = false;

            return null;
        }

        // The first construction of a class only records that it happened.
        // Composing a builder walks the whole graph below the class and
        // allocates a closure per node, which costs more than the single
        // construction it would replace — so a container that builds a class
        // once never earns it back, and a fresh container per request is how a
        // framework uses this (#181). The second construction is what pays.
        if ($cached === null) {
            $this->argBuilders[$className] = true;

            return null;
        }

        return $this->composeFor($className);
    }

    /**
     * Forget every builder.
     *
     * Called for any registration, because a binding, an alias or a stored
     * instance can change what a class resolves to anywhere in a graph that a
     * builder has already flattened. Registration is a bootstrap-time
     * operation and resolution is not, so throwing the memos away on write is
     * the right way round.
     */
    public function dropArgBuilders(): void
    {
        $this->argBuilders = [];
    }

    /**
     * Build the plan for $className and for everything its constructor reaches,
     * without constructing any of it.
     *
     * warmUp() gets the same plans as a side effect of resolving, which is fine
     * for a hand-picked list of services you were going to build anyway and
     * wrong for a compile step: resolving runs constructors, so it opens the
     * connections and throws on the first class whose scalar nothing supplies.
     * Neither is acceptable when the input is a whole classmap.
     *
     * Reflection only, so an unplannable class is skipped rather than fatal —
     * discovery hands over everything it finds and the compiler stays the one
     * place that decides what is compilable.
     *
     * @param class-string $className
     * @param array<class-string, true> $seen guards cycles across the recursion
     */
    public function planDeep(string $className, array &$seen = []): void
    {
        if (isset($seen[$className]) || !class_exists($className)) {
            return;
        }

        $seen[$className] = true;

        try {
            $plan = $this->describeClass($className);
        } catch (Throwable) {
            // An unreadable class definition is one this build cannot compile,
            // which is the compiler's answer to give, not a reason to abort.
            return;
        }

        foreach ($plan['params'] as $param) {
            $type = $param['type'];

            if ($type === null || $param['isScalar'] || !class_exists($type)) {
                continue;
            }

            $this->planDeep($type, $seen);
        }
    }

    /**
     * Whether $className resolves to a lazy instance, whether it said so with
     * #[Lazy] or was registered through Container::lazy().
     *
     * @param class-string $className
     */
    public function isLazy(string $className): bool
    {
        if (!$this->supportsLazyObjects) {
            return false;
        }

        return isset($this->lazyClasses[$className])
            || (self::$lazyAttribute[$className] ??= (new ReflectionClass($className))->getAttributes(Lazy::class, ReflectionAttribute::IS_INSTANCEOF) !== []);
    }

    /**
     * A lazy instance of $className: a real object of that type whose
     * construction has not happened yet.
     *
     * A ghost when the constructor produces the instance, a proxy when a
     * lazy() factory does — the initializer of a ghost cannot replace the
     * object, and a factory returns a different one.
     *
     * @param class-string $className
     */
    public function newLazyInstance(string $className): object
    {
        $factory = $this->lazyFactories[$className] ?? null;

        return $factory === null
            ? $this->newLazyGhost($className)
            : $this->newLazyProxy($className, $factory);
    }

    /**
     * Whether $className declares any #[Inject] property.
     *
     * Lets a caller skip injectPropertiesOn() outright. Almost no class has
     * one, and a call per instantiation to be told "none" is measurable on the
     * shallow benchmarks.
     *
     * @param class-string $className
     */
    public function hasInjectedProperties(string $className): bool
    {
        return $this->describeProperties($className) !== [];
    }

    /**
     * Assign the #[Inject] properties of an instance built outside this class.
     *
     * Nested instances are handled by instantiateFromPlan(); this is the entry
     * point for the ones the cache manager constructs itself. Callers guard it
     * with hasInjectedProperties() rather than it short-circuiting internally,
     * so a class with nothing to inject costs no call at all.
     *
     * @param class-string $className
     */
    public function injectPropertiesOn(object $instance, string $className): void
    {
        $this->resolvingStack[$className] = true;

        try {
            $this->injectProperties($instance, $this->describeProperties($className));
        } finally {
            // The stack has to be unwound even when a property fails to
            // resolve, or the next resolution on this container reports a
            // resolution chain containing a class it never touched.
            unset($this->resolvingStack[$className]);
        }
    }

    /**
     * Whether $className declares any #[Inject] method.
     *
     * Exists for the same reason hasInjectedProperties() does: almost no class
     * has one, and being told "none" once per instantiation is measurable.
     *
     * @param class-string $className
     */
    public function hasInjectedMethods(string $className): bool
    {
        return $this->describeMethods($className) !== [];
    }

    /**
     * Call the #[Inject] methods of an instance built outside this class.
     *
     * The counterpart to injectPropertiesOn(): nested instances go through
     * instantiateFromPlan(), and this is the entry point for the ones the cache
     * manager constructs itself.
     *
     * @param class-string $className
     */
    public function callInjectedMethodsOn(object $instance, string $className): void
    {
        $this->resolvingStack[$className] = true;

        try {
            $this->callInjectedMethods($instance, $className, $this->describeMethods($className));
        } finally {
            // Unwound even when a setter's argument fails to resolve, or the
            // next resolution reports a chain containing a class it never
            // touched.
            unset($this->resolvingStack[$className]);
        }
    }

    /**
     * The builder for $className, composing it now if it has no builder yet.
     *
     * Recursion enters here rather than through argBuilderFor(), because the
     * first-construction deferral must not apply to it: once a composition has
     * started the walk is already being paid for, and a nested class being seen
     * for the first time would otherwise refuse and have its *parent* cached as
     * permanently ineligible.
     *
     * @infection-ignore-all see argBuilderFor()
     *
     * @param class-string $className
     *
     * @return (Closure(): object)|null
     */
    private function composeFor(string $className): ?Closure
    {
        $cached = $this->argBuilders[$className] ?? null;

        if ($cached === false) {
            return null;
        }

        if ($cached instanceof Closure) {
            return $cached;
        }

        // A cycle has no leaf to build upwards from, and the recursion below
        // would not terminate on one.
        if (isset($this->buildingBuilders[$className])) {
            return null;
        }

        $this->buildingBuilders[$className] = true;

        try {
            $builder = $this->composeBuilder($className);
        } finally {
            unset($this->buildingBuilders[$className]);
        }

        if ($builder === null) {
            $this->argBuilders[$className] = false;

            return null;
        }

        return $this->argBuilders[$className] = $builder;
    }

    /**
     * Whether *this container* can use builders at all.
     *
     * Every one of these is an array the resolver holds by reference, so a
     * `bind()`, a `when()` or a `lazy()` anywhere disables the mechanism on the
     * next call without anything having to be invalidated. A scope is excluded
     * outright: its ancestors can start providing a type at any time.
     *
     * Negating any single condition is caught by a test; negating all of them
     * at once only turns the optimisation off, which nothing can observe. See
     * argBuilderFor() for why that is not a coverage gap.
     *
     * @infection-ignore-all
     */
    private function eligibleForBuilders(): bool
    {
        return !$this->hasParent
            && $this->contextualBindings === []
            && $this->lazyClasses === []
            && $this->lazyFactories === [];
    }

    /**
     * A lazy class must not be flattened into a builder — construction is the
     * thing it defers.
     *
     * Only observable on PHP 8.4+. Below that there are no native lazy objects,
     * so a #[Lazy] class is constructed eagerly by the ordinary path too and
     * building it eagerly here is indistinguishable — which makes every mutant
     * of this branch equivalent on the 8.3 floor CI gates on.
     *
     * @infection-ignore-all
     *
     * @param class-string $className
     */
    private function refusesForLaziness(string $className): bool
    {
        return $this->isLazy($className);
    }

    /**
     * @param class-string $className
     *
     * @return (Closure(): object)|null
     */
    private function composeBuilder(string $className): ?Closure
    {
        $plan = $this->describeClass($className);

        // Anything the constructor alone does not settle: an abstract, a class
        // whose properties or setters are injected after construction, or one
        // deferred by #[Lazy].
        if (!$plan['instantiable'] || $plan['props'] !== [] || $plan['methods'] !== []) {
            return null;
        }

        if ($this->refusesForLaziness($className)) {
            return null;
        }

        // A bound class is whatever its binding says, now or after the next
        // call to bind(). Registration drops every builder (see
        // dropArgBuilders()), so this only has to be right at build time.
        if (isset($this->bindings[$className])) {
            return null;
        }

        $arguments = [];

        foreach ($plan['params'] as $param) {
            $type = $param['type'];

            // A scalar, an untyped parameter or an #[Inject] override can all
            // be answered by configuration, which is exactly what this may not
            // decide ahead of time. A default is no better: reading it here
            // would pin the value the plan happened to capture.
            if (!$param['hasType'] || $param['isScalar'] || $param['inject'] !== null || $type === null) {
                return null;
            }

            /** @var class-string $type */
            $nested = $this->composeFor($type);

            if ($nested === null) {
                return null;
            }

            $arguments[] = $nested;
        }

        /** @var list<Closure(): object> $arguments */
        return static function () use ($className, $arguments): object {
            $args = [];

            foreach ($arguments as $build) {
                $args[] = $build();
            }

            /** @psalm-suppress MixedMethodCall */
            return new $className(...$args);
        };
    }

    /**
     * The container this resolver belongs to, or null once it has been dropped.
     *
     * Never null on any path a binding closure runs on: those run while the
     * container is resolving. A lazy object resolves after get() returned, so
     * its initializer captures the container strongly to keep it that way.
     */
    private function container(): ?ContainerInterface
    {
        return $this->containerRef?->get();
    }

    /**
     * Entry-point parameters (top-level class or callable) must all be
     * resolvable; an untyped parameter is a hard error here.
     *
     * @param list<ParamPlan> $params
     * @param array<string, mixed> $overrides runtime values keyed by parameter name
     *
     * @return list<mixed>
     */
    private function resolveEntryParameters(array $params, array $overrides = []): array
    {
        /** @var list<mixed> $dependencies */
        $dependencies = [];

        foreach ($params as $param) {
            if (array_key_exists($param['name'], $overrides)) {
                /** @psalm-suppress MixedAssignment */
                $dependencies[] = $overrides[$param['name']];
                continue;
            }

            /** @psalm-suppress MixedAssignment */
            $dependencies[] = $this->resolveParameter($param);
        }

        return $dependencies;
    }

    /**
     * @param ParamPlan $param
     */
    private function resolveParameter(array $param): mixed
    {
        /** @psalm-suppress MixedAssignment */
        [$hasNamedBinding, $namedValue] = $this->resolveNamedContextualBinding($param);
        if ($hasNamedBinding) {
            return $namedValue;
        }

        if (!$param['hasType']) {
            throw DependencyInvalidArgumentException::noParameterTypeFor($param['name'], $this->getResolutionChain());
        }

        if ($param['isScalar'] && !$param['hasDefault']) {
            throw DependencyInvalidArgumentException::unableToResolve(
                $param['type'] ?? $param['name'],
                $param['declaringClass'] ?? '',
                $this->getResolutionChain(),
            );
        }

        if ($param['inject'] !== null) {
            return $this->resolveClass($param['inject']);
        }

        if ($param['hasDefault']) {
            return $param['default'];
        }

        /** @var class-string $type */
        $type = $param['type'];

        return $this->resolveClass($type);
    }

    /**
     * Resolve a contextual binding matched by the parameter name (e.g. `'$apiKey'`)
     * scoped to the parameter's declaring class.
     *
     * @param ParamPlan $param
     *
     * @return array{bool, mixed} [matched, value]
     */
    private function resolveNamedContextualBinding(array $param): array
    {
        if ($this->contextualBindings === []) {
            return [false, null];
        }

        $declaringClass = $param['declaringClass'];
        if ($declaringClass === null) {
            return [false, null];
        }

        $bindings = $this->contextualBindings[$declaringClass] ?? null;
        if ($bindings === null) {
            return [false, null];
        }

        // array_key_exists rather than isset: null is a value a caller can bind
        // — "this consumer gets no logger" is a real answer — and isset() calls
        // it absent.
        $key = '$' . $param['name'];
        if (!array_key_exists($key, $bindings)) {
            return [false, null];
        }

        /** @var mixed $value */
        $value = $bindings[$key];
        if (is_callable($value)) {
            /** @psalm-suppress MixedFunctionCall */
            return [true, $value($this->container())];
        }

        return [true, $value];
    }

    /**
     * @param class-string $paramTypeName
     */
    private function resolveClass(string $paramTypeName): mixed
    {
        /** @psalm-suppress MixedAssignment */
        $contextualBinding = $this->getContextualBinding($paramTypeName);
        if ($contextualBinding !== null) {
            // Same ordering as BindingResolver::resolve(): a class-string is
            // the common case, so it never pays the function-table lookup
            // is_callable() does on a string — nor risks that lookup answering
            // true for a class whose name collides with a function's.
            if (!is_string($contextualBinding) && is_callable($contextualBinding)) {
                /** @psalm-suppress MixedFunctionCall */
                return $contextualBinding($this->container());
            }

            if (is_object($contextualBinding)) {
                return $contextualBinding;
            }

            // It's a class string - use it instead of the interface
            /** @var class-string $contextualBinding */
            $paramTypeName = $contextualBinding;
        }

        $bindClass = $this->bindings[$paramTypeName] ?? null;

        // An ancestor that already owns this type hands over its own instance,
        // so a scope shares it instead of building a second one.
        if ($this->hasParent && $bindClass === null && $this->parent?->provides($paramTypeName) === true) {
            return $this->parent->get($paramTypeName);
        }

        // The hottest of the three sites with this ordering: it runs once per
        // constructor parameter of every node of every graph. A class-string
        // binding is settled below, by the plan, so it never pays the
        // function-table lookup — nor risks that lookup answering true for a
        // class whose name a function shares.
        if (!is_string($bindClass) && is_callable($bindClass)) {
            if ($this->supportsLazyObjects && isset($this->lazyFactories[$paramTypeName])) {
                return $this->newLazyInstance($paramTypeName);
            }

            return $bindClass($this->container());
        }

        if (is_object($bindClass)) {
            return $bindClass;
        }

        $this->checkCircularDependency($paramTypeName);

        $plan = $this->describeClass($paramTypeName);
        if (!$plan['instantiable']) {
            $paramTypeName = $this->resolveConcreteForAbstract($paramTypeName);
            $plan = $this->describeClass($paramTypeName);
        }

        // Deferred here rather than in instantiateFromPlan(): a lazy target must
        // not have its constructor arguments resolved yet, and that is what the
        // plan-driven path exists to do.
        //
        // The field guards the call rather than isLazy() alone: on PHP 8.3
        // nothing is ever lazy, and this runs for every node of every graph.
        if ($this->supportsLazyObjects && $this->isLazy($paramTypeName)) {
            return $this->newLazyInstance($paramTypeName);
        }

        return $this->instantiateFromPlan($paramTypeName, $plan);
    }

    /**
     * @param class-string $abstract
     *
     * @return class-string
     */
    private function resolveConcreteForAbstract(string $abstract): string
    {
        $concrete = $this->bindings[$abstract] ?? null;
        if (is_string($concrete)) {
            /** @var class-string $concrete */
            return $concrete;
        }

        $knownBindings = $this->parent === null
            ? $this->bindings
            : $this->bindings + $this->parent->getBindings();

        $suggestions = FuzzyMatcher::findSimilar($abstract, array_keys($knownBindings));

        throw DependencyNotFoundException::mapNotFoundForClassName($abstract, $suggestions);
    }

    /**
     * A real instance of $className whose constructor runs on first use.
     *
     * Dependencies are resolved inside the initializer, not before it, so a
     * lazy service that is never touched costs nothing to build.
     *
     * @param class-string $className
     */
    private function newLazyGhost(string $className): object
    {
        $reflection = new ReflectionClass($className);

        // Captured strongly and never read: the capture alone is what keeps the
        // container its constructor will need alive, since an untouched ghost may
        // outlive every other reference to it. PHP releases an initializer once it
        // has run, ending the hold exactly when the ghost stops needing it.
        $ownerContainer = $this->container();

        /**
         * newLazyGhost() is PHP 8.4+, and the analysers target the 8.3 floor,
         * so they cannot see it. Callers gate this behind a runtime capability
         * check, which is exactly what they cannot model.
         *
         * Calling __construct() directly on the ghost is the documented way to
         * initialize one, not an accident.
         *
         * $ownerContainer reads as unused because its purpose is the capture
         * itself, which is a lifetime, and a lifetime is the other thing they
         * cannot model.
         *
         * @psalm-suppress UndefinedMethod, MixedReturnStatement, MixedInferredReturnType
         *
         * @phpstan-ignore method.notFound, return.type, closure.unusedUse
         */
        return $reflection->newLazyGhost(function (object $instance) use ($className, $ownerContainer): void {
            $dependencies = $this->resolveDependencies($className);

            /**
             * @psalm-suppress MixedMethodCall, DirectConstructorCall
             *
             * @phpstan-ignore method.notFound
             */
            $instance->__construct(...$dependencies);

            // Deferred with the constructor, not run eagerly at ghost creation:
            // resolving them up front would defeat the point of being lazy.
            if ($this->hasInjectedProperties($className)) {
                $this->injectPropertiesOn($instance, $className);
            }

            // Inside the initializer with the constructor, for the same reason:
            // running them at ghost creation would resolve the very graph
            // laziness exists to defer.
            $this->callInjectedMethods($instance, $className, $this->describeMethods($className));
        });
    }

    /**
     * A real instance of $className produced by $factory on first use.
     *
     * @param class-string $className
     */
    private function newLazyProxy(string $className, Closure $factory): object
    {
        $reflection = new ReflectionClass($className);

        // Captured strongly for the same reason a ghost does it: $factory is
        // handed this container on first touch, which may be long after the
        // caller dropped its own reference.
        $container = $this->container();

        /**
         * newLazyProxy() is PHP 8.4+; see newLazyGhost() for why the analysers
         * cannot see it.
         *
         * @psalm-suppress UndefinedMethod, MixedReturnStatement, MixedInferredReturnType
         *
         * @phpstan-ignore method.notFound, return.type
         */
        return $reflection->newLazyProxy(static function () use ($factory, $container): object {
            /** @var object */
            return $factory($container);
        });
    }

    /**
     * @param class-string $className
     * @param ClassPlan $plan
     */
    private function instantiateFromPlan(string $className, array $plan): object
    {
        $this->resolvingStack[$className] = true;

        try {
            /** @var list<mixed> $args */
            $args = [];

            foreach ($plan['params'] as $param) {
                // Nested constructors skip untyped parameters, relying on their defaults.
                if (!$param['hasType']) {
                    continue;
                }

                /** @psalm-suppress MixedAssignment */
                $args[] = $this->resolveParameter($param);
            }

            /** @psalm-suppress MixedMethodCall */
            $instance = new $className(...$args);

            // Read off the plan already in hand, and skipped entirely when
            // there is nothing to inject — this runs for every node of every
            // object graph.
            if ($plan['props'] !== []) {
                // Still inside the try, so the class remains on the resolving
                // stack: a cycle reached through a property is caught like any
                // other.
                $this->injectProperties($instance, $plan['props']);
            }

            // After the constructor and after the properties, which is the
            // documented order: a setter can read what the two of them set.
            if ($plan['methods'] !== []) {
                $this->callInjectedMethods($instance, $className, $plan['methods']);
            }

            return $instance;
        } finally {
            unset($this->resolvingStack[$className]);
        }
    }

    /**
     * @param class-string $className
     *
     * @return ClassPlan
     */
    private function describeClass(string $className): array
    {
        $plan = $this->planRegistry->plans[$className] ?? null;

        if ($plan === null) {
            $reflection = new ReflectionClass($className);
            $constructor = $reflection->getConstructor();

            $params = [];
            if ($constructor !== null) {
                foreach ($constructor->getParameters() as $parameter) {
                    $params[] = $this->describeParameter($parameter);
                }
            }

            return $this->planRegistry->plans[$className] = [
                'instantiable' => $reflection->isInstantiable(),
                'params' => $params,
                'props' => $this->describeProperties($className),
                'methods' => $this->describeMethods($className),
            ];
        }

        // A cache written before property injection existed has no 'props', and
        // one written before method injection has no 'methods'. Filling them in
        // beats trusting them: a stale file would otherwise silently turn
        // injection off in exactly the environment (production) where the cache
        // is used.
        if (!isset($plan['props']) || !isset($plan['methods'])) {
            return $this->planRegistry->plans[$className] = [
                'instantiable' => $plan['instantiable'],
                'params' => $plan['params'],
                'props' => $plan['props'] ?? $this->describeProperties($className),
                'methods' => $plan['methods'] ?? $this->describeMethods($className),
            ];
        }

        return $plan;
    }

    /**
     * The #[Inject] properties of $className, including inherited private ones.
     *
     * @param class-string $className
     *
     * @return list<PropPlan>
     */
    private function describeProperties(string $className): array
    {
        return self::$propertyPlans[$className] ??= $this->scanProperties($className);
    }

    /**
     * @param class-string $className
     *
     * @return list<PropPlan>
     */
    private function scanProperties(string $className): array
    {
        $reflection = new ReflectionClass($className);

        // The leaf lists its own properties plus inherited public and protected
        // ones. Private properties declared upstream are the only gap, and each
        // ancestor reports exactly its own, so the walk cannot double up — a
        // private property shadowing a parent's stays a separate entry.
        $properties = $reflection->getProperties();

        for ($parent = $reflection->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            foreach ($parent->getProperties(ReflectionProperty::IS_PRIVATE) as $property) {
                $properties[] = $property;
            }
        }

        $props = [];

        foreach ($properties as $property) {
            // A promoted property carries the parameter's attribute, and the
            // constructor already acted on it.
            if ($property->isStatic() || $property->isPromoted()) {
                continue;
            }

            $plan = $this->describeProperty($property);
            if ($plan !== null) {
                $props[] = $plan;
            }
        }

        return $props;
    }

    /**
     * The #[Inject] methods of $className, in declaration order.
     *
     * @param class-string $className
     *
     * @return list<MethodPlan>
     */
    private function describeMethods(string $className): array
    {
        return self::$methodPlans[$className] ??= $this->scanMethods($className);
    }

    /**
     * Every #[Inject] method, including the ones that cannot be called.
     *
     * A static or non-public one is kept rather than filtered out so that
     * callInjectedMethods() can refuse it by name, the way readonly property
     * injection is refused: silently skipping an annotation someone wrote is
     * the worse failure, because nothing anywhere says the dependency never
     * arrived.
     *
     * @param class-string $className
     *
     * @return list<MethodPlan>
     */
    private function scanMethods(string $className): array
    {
        $reflection = new ReflectionClass($className);
        $methods = [];

        foreach ($reflection->getMethods() as $method) {
            if ($method->getAttributes(Inject::class, ReflectionAttribute::IS_INSTANCEOF) === []) {
                continue;
            }

            // The constructor is injected by being the constructor.
            if ($method->isConstructor()) {
                continue;
            }

            $params = [];
            foreach ($method->getParameters() as $parameter) {
                $params[] = $this->describeParameter($parameter);
            }

            /** @var class-string $declaringClass */
            $declaringClass = $method->getDeclaringClass()->getName();

            $methods[] = [
                'name' => $method->getName(),
                'params' => $params,
                'isStatic' => $method->isStatic(),
                'isPublic' => $method->isPublic(),
                'declaringClass' => $declaringClass,
            ];
        }

        return $methods;
    }

    /**
     * Call the #[Inject] methods of $instance, in declaration order.
     *
     * Ordering is observable and therefore documented: after the constructor,
     * after property injection, and among themselves in the order the class
     * declares them.
     *
     * @param class-string $className the class being built, pushed onto the
     *   build stack so a setter's arguments see the same contextual bindings a
     *   constructor's would
     * @param list<MethodPlan> $methods
     */
    private function callInjectedMethods(object $instance, string $className, array $methods): void
    {
        $this->buildStack[] = $className;

        try {
            $this->callEach($instance, $methods);
        } finally {
            array_pop($this->buildStack);
        }
    }

    /**
     * @param list<MethodPlan> $methods
     */
    private function callEach(object $instance, array $methods): void
    {
        foreach ($methods as $method) {
            if ($method['isStatic']) {
                throw DependencyInvalidArgumentException::staticMethodInjection(
                    $method['declaringClass'],
                    $method['name'],
                    $this->getResolutionChain(),
                );
            }

            if (!$method['isPublic']) {
                throw DependencyInvalidArgumentException::nonPublicMethodInjection(
                    $method['declaringClass'],
                    $method['name'],
                    $this->getResolutionChain(),
                );
            }

            /** @var list<mixed> $args */
            $args = [];

            foreach ($method['params'] as $param) {
                /** @psalm-suppress MixedAssignment */
                $args[] = $this->resolveParameter($param);
            }

            /** @psalm-suppress MixedMethodCall */
            $instance->{$method['name']}(...$args);
        }
    }

    /**
     * @return PropPlan|null null when the property carries no #[Inject]
     */
    private function describeProperty(ReflectionProperty $property): ?array
    {
        $inject = self::injectAttributeOf($property);
        if ($inject === null) {
            return null;
        }

        $type = $property->getType();
        [$typeName, $isScalar] = $this->describeType($type);

        /** @var class-string|null $implementation */
        $implementation = $inject->implementation;

        return [
            'name' => $property->getName(),
            'hasType' => $type instanceof ReflectionNamedType,
            'type' => $typeName,
            'isScalar' => $isScalar,
            'inject' => $implementation,
            'isReadonly' => $property->isReadOnly(),
            'declaringClass' => $property->getDeclaringClass()->getName(),
        ];
    }

    /**
     * @param list<PropPlan> $props
     */
    private function injectProperties(object $instance, array $props): void
    {
        foreach ($props as $prop) {
            if ($prop['isReadonly']) {
                throw DependencyInvalidArgumentException::readonlyPropertyInjection(
                    $prop['declaringClass'],
                    $prop['name'],
                    $this->getResolutionChain(),
                );
            }

            $this->handleFor($prop)->setValue($instance, $this->resolveProperty($prop));
        }
    }

    /**
     * @param PropPlan $prop
     */
    private function resolveProperty(array $prop): mixed
    {
        $declaringClass = $prop['declaringClass'];

        if ($prop['inject'] !== null) {
            return $this->resolveClass($prop['inject']);
        }

        if (!$prop['hasType']) {
            throw DependencyInvalidArgumentException::noPropertyTypeFor(
                $declaringClass,
                $prop['name'],
                $this->getResolutionChain(),
            );
        }

        /** @var string $type hasType was just checked, so this is never null */
        $type = $prop['type'];

        if ($prop['isScalar']) {
            throw DependencyInvalidArgumentException::unableToResolveProperty(
                $declaringClass,
                $prop['name'],
                $type,
                $this->getResolutionChain(),
            );
        }

        /** @var class-string $type */
        return $this->resolveClass($type);
    }

    /**
     * @param PropPlan $prop
     */
    private function handleFor(array $prop): ReflectionProperty
    {
        return $this->propertyHandles[$prop['declaringClass'] . '::' . $prop['name']]
            ??= new ReflectionProperty($prop['declaringClass'], $prop['name']);
    }

    /**
     * The parameter plan of a callable, memoized.
     *
     * Every other reflection result here is memoized and this one was not, so a
     * repeated resolve() re-built a ReflectionFunction and re-described every
     * parameter each time — costing more than the resolution it was feeding.
     *
     * @return list<ParamPlan>
     */
    private function describeCallable(callable $callable): array
    {
        $signature = CallableKey::signatureFor($callable);

        if ($signature !== null) {
            // Closure::fromCallable() allocates a closure for every form but a
            // Closure, so it is built here on a miss rather than by the caller
            // on every call: with the plan cached there is nothing to reflect
            // and nothing to convert.
            return $this->callablePlans[$signature] ??= $this->describeFunction(Closure::fromCallable($callable));
        }

        // A closure has no name to key on, so the object is its own identity —
        // weakly, or a container would pin every closure ever passed to
        // resolve() for as long as it lived.
        /** @var Closure $callable a null signature means exactly this */
        $plans = $this->closurePlans;

        if ($plans === null) {
            /** @var WeakMap<Closure, list<ParamPlan>> $plans */
            $plans = new WeakMap();
            $this->closurePlans = $plans;
        }

        $plan = $plans[$callable] ?? null;

        if ($plan === null) {
            $plan = $this->describeFunction($callable);
            $plans[$callable] = $plan;
        }

        return $plan;
    }

    /**
     * @return list<ParamPlan>
     */
    private function describeFunction(Closure $closure): array
    {
        $params = [];
        foreach ((new ReflectionFunction($closure))->getParameters() as $parameter) {
            $params[] = $this->describeParameter($parameter);
        }

        return $params;
    }

    /**
     * @return ParamPlan
     */
    private function describeParameter(ReflectionParameter $parameter): array
    {
        [$typeName, $isScalar] = $this->describeType($parameter->getType());

        $hasDefault = $parameter->isDefaultValueAvailable();

        return [
            'name' => $parameter->getName(),
            'hasType' => $parameter->hasType(),
            'type' => $typeName,
            'isScalar' => $isScalar,
            'inject' => $this->readInjectImplementation($parameter),
            'hasDefault' => $hasDefault,
            'default' => $hasDefault ? $parameter->getDefaultValue() : null,
            'declaringClass' => $parameter->getDeclaringClass()?->getName(),
        ];
    }

    /**
     * @return class-string|null
     */
    private function readInjectImplementation(ReflectionParameter $parameter): ?string
    {
        /** @var class-string|null */
        return self::injectAttributeOf($parameter)?->implementation;
    }

    /**
     * The #[Inject] a parameter or a property carries, or null when it carries
     * none.
     *
     * One place rather than two: the parameter path wanted only the
     * implementation and the property path wanted the whole attribute, so the
     * same three steps were written twice — and disagreed on how to test for
     * "no attribute". Runs while a plan is being built, which is once per class
     * per process, never per resolution.
     *
     * IS_INSTANCEOF is what makes a consumer's own subclass of Inject count;
     * see the attribute's docblock.
     */
    private static function injectAttributeOf(ReflectionParameter|ReflectionProperty $target): ?Inject
    {
        $attributes = $target->getAttributes(Inject::class, ReflectionAttribute::IS_INSTANCEOF);

        if ($attributes === []) {
            return null;
        }

        /** @var Inject */
        return $attributes[0]->newInstance();
    }

    /**
     * A type's name, and whether it is a scalar — the two things a parameter
     * plan and a property plan describe identically.
     *
     * A union or intersection type yields [null, false], the same as no type at
     * all: neither plan can pick a class out of one. The two plans still differ
     * on `hasType`, which is why that stays with each caller — a parameter
     * reports a union as typed, a property does not.
     *
     * @return array{string|null, bool}
     */
    private function describeType(?ReflectionType $type): array
    {
        if (!$type instanceof ReflectionNamedType) {
            return [null, false];
        }

        $typeName = $type->getName();

        return [$typeName, $this->isScalar($typeName)];
    }

    /**
     * @return list<string>
     */
    private function getResolutionChain(): array
    {
        return array_keys($this->resolvingStack);
    }

    private function isScalar(string $paramTypeName): bool
    {
        $this->typeExistsCache[$paramTypeName] ??= class_exists($paramTypeName)
            || interface_exists($paramTypeName);

        return !$this->typeExistsCache[$paramTypeName];
    }

    /**
     * @param class-string $className
     */
    private function checkCircularDependency(string $className): void
    {
        if (isset($this->resolvingStack[$className])) {
            $chain = array_keys($this->resolvingStack);
            $chain[] = $className;
            throw CircularDependencyException::create($chain);
        }
    }

    /**
     * @param class-string $abstract
     */
    private function getContextualBinding(string $abstract): mixed
    {
        if ($this->contextualBindings === []) {
            return null;
        }

        // Walk the build stack from the end (most specific context) outward
        for ($i = count($this->buildStack) - 1; $i >= 0; --$i) {
            $concrete = $this->buildStack[$i];
            if (isset($this->contextualBindings[$concrete][$abstract])) {
                return $this->contextualBindings[$concrete][$abstract];
            }
        }

        return null;
    }
}
