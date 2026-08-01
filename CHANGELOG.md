# Changelog

Notable changes to `gacela-project/container`.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning: [Semantic Versioning](https://semver.org/) from 1.0.0 — see the
[BC policy](docs/backward-compatibility.md).

**Breaking changes are marked ⚠.**

## Unreleased

**Breaking changes are marked ⚠.** Only code that *implements* a container
interface has to act — see [UPGRADE.md](UPGRADE.md). Callers are unaffected.

### Changed

- ⚠ **`ContainerInterface` declares the whole surface.** `createScope()`, `provides()`, `stats()`, `lazy()`, `load()`, `loadFile()`, `taggedByKey()`, `taggedKeys()`, `dependencyGraph()`, `compileReport()`, `writeCompiledFactories()`, `useCompiledFactories()`, `validate()` and `withSelfReference()` are on it now. 1.x promised nothing would be added, which is why most of the 1.2–1.5 feature set was reachable only through the concrete `final class Container`, and why 1.5 answered additively with `FullContainerInterface`; this is the merge that promise was deferring. Only an implementor has to act, and the compiler names what is missing. `FullContainerInterface` remains as a **deprecated** empty alias, so a 1.5 type-hint keeps compiling and keeps accepting a `Container`; it goes at 3.0
- ⚠ **`load()` and `loadFile()` return the ids they registered** rather than `void` — the only reliable answer to "what did this source register", since reading the ids back off the container misses aliases, which live in a third registry. Ignoring a return value is always valid, so nothing breaks for a caller. The optional listener added alongside them still works for a consumer that wants the ids one at a time
- ⚠ `afterResolving()` on a **class or interface** id fires for every resolved instance of it, not only when that exact id was asked for. "After anything implementing `LoggerAwareInterface` is built, hand it the logger" was impossible to express and had to be reimplemented on top; it is one call now. Any other id still matches exactly. Hooks also fire for `make()` with overridden arguments, which took its own path and skipped them, and a callback that **throws now removes the instance** — a service whose post-construction wiring failed should not reach the next caller as though it had succeeded

### Added

- `Container::validate()` proves a set of classes resolves **without resolving them**, so broken wiring fails a deploy instead of a request. It reports what is decidable — a missing class, an interface or abstract with nothing bound, a parameter nothing can supply, a cycle — each with the chain that reached it, and lists every problem rather than stopping at the first. Nothing is constructed: classes are described, and whether an id is satisfiable is answered by `has()` on your own container, so it cannot drift from resolution by re-deriving it. `vendor/bin/gacela-container validate` exits non-zero on any problem. See [performance](docs/performance.md#proving-it-resolves-before-it-runs)
- `Container::withSelfReference()` tells the container what to hand service closures, which is what a container designed to be wrapped owes its wrapper. `Container` is `final`, so a decorator composes — and then every user closure receives the *inner* container. The workaround was re-wrapping each closure, which silently drops the marks `factory()` and `protect()` set **by identity**. One call replaces it, and nothing is re-wrapped so no mark is lost. The reference is weak, so a decorator holding its inner container does not rebuild a cycle
- `loadFile()` reads `.yaml` and `.yml` when `symfony/yaml` is installed — a **`suggest`, never a dependency**, so `psr/container` remains the only runtime requirement and a `.yaml` file without a parser throws naming what to install. The parsed array goes through the same `load()` as every other format. See [definitions](docs/definitions.md#yaml-if-you-already-have-a-parser)
- The four attributes are **no longer `final`**, and every attribute read passes `ReflectionAttribute::IS_INSTANCEOF`, so a consumer can subclass `#[Inject]`, `#[Singleton]`, `#[Factory]` or `#[Lazy]` to re-present them under its own namespace. An exact-FQN match follows neither a subclass nor a `class_alias()`, and the failure was silent — the dependency simply never arrived. See [attributes](docs/attributes.md#re-presenting-these-under-your-own-namespace)

### Performance

- Warm resolution memoizes a per-class constructor and takes the resolution path off the graph once a class is proven statically buildable: a warm four-level chain goes **1.475μs → 0.656μs (−54%)**, past what generated factories achieve with the same reflection cached, and `make()` without runtime arguments −53%. The cost is on the cold path and is real — building the closures is work a thrown-away container never amortises, so cold subjects are **+7 to +11%**. A long-lived container halves its resolution; a per-request one pays about 8%. A builder is refused for anything configuration can influence, refusal propagates, registration drops every builder, and every wrong answer falls back to the resolution path rather than producing a wrong object. See [performance](docs/performance.md#the-warm-path-builds-straight-to-new)
- Recorded, not fixed: 1.5.0 was **~4.4% slower than 1.4.0** on the warm path (20 paired samples, t = 7.15) with *no single cause* — identical call counts, 14% more compiled code in the loaded set, of which `DependencyResolver` is 62%. Splitting that out is worth about 1%, at the edge of measurable, against restructuring the most safety-critical class. See [performance](docs/performance.md#what-150-cost-the-warm-path)

### Fixed

- `factory()` and `protect()` marked closures in a structure that held them **strongly**, and nothing ever removed an entry — there is no hook for a binding being overwritten, and `factory()` marks a closure before anyone decides to register it. A container therefore retained every closure it was ever handed and everything each closed over: 5000 never-registered `factory()` closures held 2.3mb against zero live bindings. Both marks are `WeakMap`s now, so a mark lasts exactly as long as the closure it marks
- `give(null)` threw instead of injecting `null`. Every other value already worked, but the lookup used `isset()`, which reports a bound `null` as absent — so the one binding that says "this consumer gets nothing" was the only one that could not be expressed. For a **type** need `null` is now refused with a message saying why. See [bindings](docs/bindings.md#named-scalar-contextual-bindings)
- The benchmark job failed on pushes to `main` and could not have done otherwise: on a push the merge base *is* the commit, so it compared byte-identical code against itself — and still reported **+7.6% to +12.7%** on four subjects, which is how the shared-runner noise floor was measured. The comparison now runs on pull requests only, and the ±7% per-subject budget is gone; the ±20% cliff and the deterministic ±5% memory gate remain

## [1.5.0](https://github.com/gacela-project/container/compare/1.4.0...1.5.0) - 2026-08-01

### Added

- `FullContainerInterface` — the whole of what a `Container` does, as a contract. Eleven methods were reachable only through the concrete `final class Container`, so following the library's own advice and depending on the interface cost you scopes, definitions-as-data, lazy registration, keyed tags, typed stats and everything about compiled factories. **`ContainerInterface` is untouched**, so the 1.x promise that nothing is added to it holds literally and no existing implementor is affected. `createScope()` is typed `static`, so a decorator's scope is a decorator. These move onto `ContainerInterface` at 2.0 and this name stays as a deprecated alias, so code written against it now does not migrate twice. See [api reference](docs/api-reference.md#what-the-interface-guarantees)
- `#[Inject]` works on a public method, so the container can call a setter: `#[Inject] public function setClock(Clock $clock): void`. Arguments resolve through the same path a constructor's do — contextual bindings, a nested `#[Inject]` naming an implementation, defaults — and the calls run after the constructor and after property injection, in declaration order. A **static or non-public** annotated method is refused by name rather than skipped, since a silently ignored annotation is a dependency that never arrived with nothing to say so. `#[Lazy]` still defers the calls; `ContainerCompiler` refuses the class under a new `CompilationSkipReason::InjectedMethod`. An escape hatch for constructors that are not yours, not an equal alternative — see [best practices](docs/best-practices.md#when-to-reach-for-setter-injection)
- `ClassSource` gives the compile calls somewhere to get their classes from, instead of a `list<class-string>` that nothing produced and every application maintained by hand: `fromComposerClassmap()`, `fromDirectory('src/')`, `fromList()`. Accepted by `compile()`, `compileReport()`, `writeCompiledCache()` and `writeCompiledFactories()` — and deliberately **not** by `warmUp()`, which *resolves*: a discovered set is described instead, so nothing is constructed and an unplannable class is skipped rather than fatal, where warming a classmap would build the application and throw on the first unsatisfiable scalar. Passing a list behaves exactly as before. See [performance](docs/performance.md#finding-the-classes-to-compile)
- `vendor/bin/gacela-container compile|report` ships the bootstrap script every application was otherwise writing by hand. `report` prints a `CompilationSkipReason` per refusal and `--strict` exits non-zero, so a build can assert that a class it expects to be compiled actually is. Configuration is a `gacela-container.php` returning *your* container, which is load-bearing rather than convenient: the generator refuses a *bound* class, so a container built without your bindings would emit a `new` for something the application binds. No new dependency, and `@internal` — a build tool, not a BC surface. See [performance](docs/performance.md#compiling-from-the-command-line)
- `dependencyGraph()` returns the dependency graph as a tree, where `getDependencyTree()` returns a flat deduplicated list — and flattening removes exactly what a dependency inspector is opened for. A `DependencyNode` carries how deep something sits, which constructor parameter asked for it, that several parents pull in the same class, and where a cycle closes; `render()` prints an indented tree you can `echo` from a breakpoint. A cycle is marked and cut rather than thrown, since inspecting a broken graph is precisely when this is reached for. `getDependencyTree()` is unchanged and now derived from the graph, so the two cannot disagree. See [cookbook](docs/cookbook.md#work-out-why-something-resolves-to-the-wrong-thing)

### Performance

- Whether a class carries `#[Singleton]` or `#[Factory]` was cached per container, so every container reflected every class it resolved to re-derive an answer that belongs to the class definition and cannot change while the process runs. The verdict is shared across containers now, and cleared by `resetStaticCaches()` like the other class-shape memos: cold resolution of a four-level chain is 6.0% faster, and ten sibling containers hold 9.4% less peak memory — 16.9% when they also share a plan cache
- `resolve()` re-reflected its callable on every call, so invoking one cost more in `ReflectionFunction` than in the resolution it was feeding. Parameter plans are memoized now — by `Class::method` where the callable has a name, so two instances share one plan, and by the closure itself through a `WeakMap` where it does not. Invoking a one-argument method through the container is 27% faster
- A container built a `TagRegistry` and a `DependencyTreeAnalyzer` whether or not it ever grouped a service under a tag or was asked for a dependency tree, and `createScope()` paid for both per scope — the operation whose whole point is being cheap enough to run per request. Both are built on first use: creating a scope is 10.5% faster and a cold four-level resolve 3.4% faster, for 1% less peak memory
- Reading a stored instance ran `method_exists($instance, '__invoke')` every time, to answer a question its class settles once. Memoized per class now and cleared by `resetStaticCaches()`: reading a `set()` instance is 6.4% faster

### Documentation

- `ContainerStats::memoryUsageBytes` is documented as what it is: `memory_get_usage(true)`, the whole PHP process, not the container. Sat among five container-scoped counters it read as "what this container costs", which is not the question it answers — it moves when anything anywhere allocates, and two containers in the same process report the same number. The value is unchanged, and the field is renamed `processMemoryBytes` at 2.0. See [introspection](docs/services.md#introspection)

### Fixed

- A binding was tested with `is_callable()` before `is_string()` in all three places one is read, including the lookup that runs once per constructor parameter of every node of every graph. On a class-string that a defined function happens to share a name with, `is_callable()` answers *true* and the binding was invoked instead of instantiated — the wrong object, silently, with nothing raised anywhere. Strings are settled as class names now and never reach the function table
- `has()` remembered a negative: an id that was not loadable the first time it was asked about kept answering `false` for the rest of that container's life, contradicting the "only positives are cached" guarantee [`resetStaticCaches()`](docs/performance.md#process-global-caches) documents. A class declared after the first probe — generated code, a `class_alias()`, a suite that declares as it goes — is now seen. The three caches that answered "can this be instantiated?" are also one, answered off the class plan `get()` needs anyway, so a `has()` miss no longer builds a throwaway `ReflectionClass`. The cost is that `has()` on an id that is not a class re-runs `class_exists()` rather than answering from a stale memo, 0.124μs → 0.139μs; `has()` on an autowirable class is unchanged
- Dropping a container released it only when the cycle collector next ran, because three of its collaborators held a strong reference back to it and made every container a cycle of nine objects. `createScope()` documents the opposite, and a worker creating a scope per request accumulated every scope and everything it resolved — forever under `gc_disable()`. A thousand cold containers held 1.51mb that way, ~120kb of it surviving an explicit `gc_collect_cycles()`; the same loop retains nothing now. The back-pointer is one `WeakReference`, so refcounting alone frees a dropped container. An *uninitialized* lazy object is the deliberate exception: it resolves after `get()` returned, so it keeps its container alive until first touch. See [scopes](docs/scopes.md#disposal-is-dropping-the-reference)
- A `bind()` or `singleton()` for a class with a generated factory was silently ignored: `useCompiledFactories()` was consulted before the bindings, so `bind(Mailer::class, LoggingMailer::class)` kept handing back the generated `new Mailer`, and `singleton(Mailer::class)` built a fresh instance per `get()` while the application believed the service was shared. The generator refuses to emit anything for a bound class, so the file always agreed with the container it was written *from* — it just could not speak for the one it was installed into. Registration on the receiving container now outranks a generated expression. See [performance](docs/performance.md#registration-still-wins)

### Internal

- The benchmark job is required rather than advisory, and every subject is gated rather than five of twenty-three: ±20% on time as a cliff detector, ±5% on peak memory, and a tighter ±7% on the five subjects `docs/performance.md` quotes figures for. Both thresholds are declared on the class, so a new benchmark is gated by existing. Verified in both directions — a deliberate 10% slowdown fails and an unchanged tree passes. What makes the gates able to fire at all: iterations deviating 5% from the mean are re-run and CI warms the caches with a discarded run first, so the suite reports under ±3.2% against the ±40% the first `phpbench` invocation of a session used to measure — and one assertion #83 added sat on a *setup* method, asserting nothing. The comparison table is posted on the pull request, and `composer bench:compare` runs the same A/B locally. See [performance](docs/performance.md#what-is-gated-and-at-what)

## [1.4.0](https://github.com/gacela-project/container/compare/1.3.0...1.4.0) - 2026-07-28

### Added

- Keyed tags: `tag(['email' => EmailHandler::class], 'handlers')` and `taggedByKey('handlers', 'email')` resolve *the* handler for a key rather than all of them. Only the entry asked for is built and it comes from the container's own cache, so a keyed tag is a lookup table of ids and not a second place instances live. An unknown key throws naming the keys that exist; `taggedKeys()` lists them. See [tags](docs/bindings.md#keyed-tags)
- `PlanCache` shares one constructor-plan cache between containers that are not related, so sibling roots — one per module — stop re-planning what they have in common: ten containers resolving the same four-level chain go from 55.315μs / 82.61mb to 37.067μs / 45.79mb. Only reflection output travels; bindings, contextual bindings, instances and singletons stay private to each container. See [performance](docs/performance.md#one-plan-cache-for-several-containers)
- `Container::resetStaticCaches()` clears the four reflection caches that outlive every container, for the processes where the set of loadable classes changes: code generation, a cache-warm command, a worker that re-bootstraps. Each cache is documented by lifetime — keyed on a class's shape, so cleared; or on the PHP binary, so recomputed identically. See [performance](docs/performance.md#process-global-caches)
- Compiled entries are stamped with the file their class was declared in, and `loadCompiledCache()` and the new `loadCompiledFactories()` drop the ones whose file has changed: a stale entry behaves exactly like a missing one, so the class falls back to reflection instead of being built with a constructor signature it no longer has. Both sides also take an optional build stamp — a deploy id, a commit sha — validating the whole file in one comparison instead of one `stat` per class. See [performance](docs/performance.md#staleness)
- `Container::compileReport()` says which classes `writeCompiledFactories()` will compile and why it refuses the rest: a `CompilationSkipReason` per refusing branch, and an explanation naming the parameter or dependency. Nothing is written, and `compiled()` is exactly what `writeCompiledFactories()` returns for the same input
- `Container::load()` and `Container::loadFile()` register services from data instead of code — a class-string, or an array with one of `singleton`, `value`, `factory` or `alias` plus an optional `tags` list; `loadFile()` reads `.php` or `.json`. Each entry performs the registration call it stands for, so nothing new happens at resolution time, and later keys override earlier ones. Still no YAML or XML parser and `psr/container` is still the only runtime dependency: parse it yourself and `load()` the array. See [definitions](docs/definitions.md)
- `Container::lazy()` defers construction without `#[Lazy]` on the class: `lazy(Vendor::class)` for a class you do not own, `lazy(Ifc::class, Impl::class)` for an abstract, and `lazy(Impl::class, fn (Container $c) => …)` for a *closure* binding, which `bind()`/`singleton()` always ran the moment the id resolved. Real instances either way, via native lazy objects — no proxy subclass, no new dependency
- The new methods above live on `Container` rather than `ContainerInterface`, which 1.x promises not to extend; they move onto the interface in 2.0

### Fixed

- A `when()` call made after `createScope()` was silently invisible to that scope, which kept resolving the *unbound* implementation — a wrong object injected in production with a green test suite. Late registrations are now handed down to the scopes that already exist, and to theirs, with a scope's own registration still shadowing the parent's; same for `useCompiledFactories()` and `lazy()`, which are copied the same way. Scope handles are weak and the resolution path is untouched, so a dropped scope is still collected and `createScope()` stays constant-time (1.006μs → 1.291μs). See [scopes](docs/scopes.md#what-does-not-fall-through)
- Laziness stopped at the top of a resolution: `#[Lazy]` was only consulted when the class was the id being resolved, so a lazy service injected into another class's constructor was built eagerly along with its whole subtree — the case the attribute exists for. Nested resolution now honours it, for `#[Lazy]` and `lazy()` alike

## [1.3.0](https://github.com/gacela-project/container/compare/1.2.1...1.3.0) - 2026-07-27

### Added

- `Container::createScope()`, returning a child container that resolves everything its parent resolves plus whatever is registered on it directly. Registration is not copied — the scope starts empty and looks upward on a miss, so creating one costs the same whether the parent holds three bindings or three thousand. Anything registered on a scope shadows the parent for that scope alone and never mutates it. Lifetime follows ownership: an id an ancestor owns is resolved by that ancestor, so every scope shares what it produces, while a singleton a scope resolves first belongs to that scope and is released with it. Since a parent keeps no reference back, dropping a scope drops everything it owned, which is what makes it usable as a request lifetime under Swoole, RoadRunner or FrankenPHP. `remove()` and `extend()` deliberately stay local; see [scopes](docs/scopes.md) for the full semantics
- `Container::provides()`, true when a container or one of its ancestors already owns an id — a binding, a stored instance, or a singleton it has resolved. It is the predicate a scope uses to decide whether to delegate upwards, and it fills the gap between `bound()`, which only knows registrations, and `has()`, which is true of anything merely autowirable. Like `stats()` in 1.2.0, both new methods live on `Container` rather than `ContainerInterface`, which 1.x promises not to extend; they move onto the interface in 2.0

### Fixed

- The resolver received the bindings map by value, snapshotting it the first time anything resolved. A `bind()` call made after that point was invisible to nested constructor resolution, so `$c->get(Anything::class); $c->bind(Ifc::class, Impl::class); $c->get(NeedsIfc::class);` threw `DependencyNotFoundException` instead of injecting `Impl` — the same three calls in the other order worked. The map is now held by reference, so late registration is seen

## [1.2.1](https://github.com/gacela-project/container/compare/1.2.0...1.2.1) - 2026-07-27

### Performance

- The instantiability guard added in 1.1.1 built a `ReflectionClass` of its own, duplicating the one the resolver builds a moment later for the same class, and memoized the verdict per container. On a cold container — one built per request, or per resolution — every `get()` therefore reflected twice. The guard now reads the answer off the class plan the resolver already produces, and the verdict is shared across containers. Cold resolution of a class with no dependencies is back to within noise of 1.1.0, from ~20% slower

## [1.2.0](https://github.com/gacela-project/container/compare/1.1.1...1.2.0) - 2026-07-27

### Added

- `#[Inject]` now targets properties as well as constructor parameters, for classes whose constructor is not yours to change. Private, protected and inherited properties are supported; static properties are ignored, and a promoted parameter is still injected only by the constructor. Constructor injection remains the default — see [attributes](docs/attributes.md#on-properties)
- A cycle reached through an injected property still raises `CircularDependencyException`. Property injection runs inside the same resolution stack, so it is deliberately not an escape hatch for the diagnostic
- `readonly`, untyped and scalar-typed `#[Inject]` properties fail with a `DependencyInvalidArgumentException` naming the property and what to do instead, rather than a raw PHP error
- `stats(): ContainerStats` returns the container's counters as a `final readonly` object instead of a shapeless array. Its properties **are** covered by backward compatibility, unlike the array from `getStats()`, and memory comes back as `memoryUsageBytes` (an int, so it can be compared and summed) with `memoryUsageFormatted()` for display. Lives on `Container` only — 1.x promises nothing will be added to `ContainerInterface` — and moves onto the interface in 2.0, replacing `getStats()`. `getStats()` is unchanged and keeps working for the whole of 1.x

### Performance

- Pass the whole class plan into instantiation instead of re-reading it per node, removing an array copy from every step of an object graph. Resolution is 5-8% faster on the chain benchmarks
- Classes without `#[Inject]` properties never enter the injection path: the check is a memoized lookup, not a call, and the property scan is cached per class for the process rather than per container

## [1.1.1](https://github.com/gacela-project/container/compare/1.1.0...1.1.1) - 2026-07-26

### Fixed

- `writeCompiledCache()` and `writeCompiledFactories()` reported success while writing nothing when the target was unwritable. Both now throw `ContainerException`, and check writability before writing rather than relying on `@`-suppression, which an application's own error handler defeats
- `loadCompiledCache()` emitted a raw PHP fatal for a missing or unreadable file, and a `TypeError` when the file returned something other than an array. Both are now `ContainerException` naming the file and how to regenerate it
- `get()`/`make()` on an abstract class, interface or otherwise non-instantiable class threw a raw PHP `Error` from inside the container. Now a `ContainerException`, consistent with `has()`, which already reported them as unresolvable

## [1.1.0](https://github.com/gacela-project/container/compare/1.0.0...1.1.0) - 2026-07-26

Upgrading from 1.0.0? Nothing to do — this release is additive plus one bug fix.

### Added

- `#[Lazy]` defers construction until an instance is first used. You get a real instance of the class, not a proxy subclass, via PHP 8.4 native lazy objects. On 8.3 it is constructed eagerly, which is unobservable apart from the timing
- `writeCompiledFactories()` / `useCompiledFactories()` generate plain `new` expressions for classes whose construction is knowable ahead of time, taking the resolver off the path — roughly 5x faster than reflection on a cold container. Deliberately conservative: bindings, scalars, attributes and cycles are left out and resolve normally

### Fixed

- `extend()` threw instead of scheduling when the id named a class the container could autowire, breaking the documented ability to extend a service before it is defined. Regression from the `has()` change in 1.0.0. `isFactory()` shared the confusion but returned the right answer by accident

### Performance

- Read `#[Singleton]`, `#[Factory]` and `#[Lazy]` in one memoized reflection pass per class rather than a concatenated cache key per attribute. Resolution is faster than before `#[Lazy]` existed

### Internal

- Move code generation and callable-key building out of `Container` into `CompiledCacheWriter` and `CallableKey`
- Extract byte formatting so it can be tested directly, and cover the resolution core's untested branches: 248 tests, Infection gate raised 80 to 87
- Gate three benchmark subjects at 20% over baseline in CI, advisory for now
- Add a cookbook of verified recipes; document every exception with its real message text
- Adopt PHP 8.3 typed class constants and `readonly` where they add type safety

## [1.0.0](https://github.com/gacela-project/container/compare/0.10.0...1.0.0) - 2026-07-26

Upgrading from 0.10.0? See [UPGRADE.md](UPGRADE.md).

### Changed

- ⚠ Require PHP >= 8.3 (was 8.1)
- ⚠ `Container` is now `final` — decorate by composition against `ContainerInterface`, or use `extend()`/`afterResolving()` per service
- ⚠ `has()` answers the PSR-11 question "will `get()` resolve this?", so it is now `true` for autowirable classes. `bound()` is unchanged and remains the check for explicit registration
- ⚠ `ContainerInterface` gained `when()`, `compile()`, `writeCompiledCache()`, `getStats()`, and extends `ArrayAccess`. Affects implementers only
- Every class is marked `@api` or `@internal`; only `@api` is covered by semver. The `getStats()` return shape is excluded

### Fixed

- Use float division in `FuzzyMatcher::calculateSimilarity()` and the stats byte formatter, which relied on implicit int/float coercion

### Performance

- Short-circuit alias resolution when no aliases are registered; it previously cached an identity entry per id, growing unboundedly. Cold-container peak memory drops 4-7%
- Short-circuit `afterResolving()` dispatch when no hooks are registered

### Internal

- CI: test on PHP 8.3/8.4/8.5; add coverage gate (95% lines) and Infection (80% MSI)
- Add a `phpbench` suite covering the resolution hot paths
- Upgrade to PHPStan 2, Psalm 6, PHPUnit 12, var-dumper 7
- Remove Scrutinizer; PHPStan, Psalm and the coverage gate cover it
- Bound `psr/container` to `^1.1 || ^2.0`, set `minimum-stability: stable`, and trim the published archive to `src/` plus metadata

## [0.10.0](https://github.com/gacela-project/container/compare/0.9.0...0.10.0) - 2026-07-20

### Added

- `bind()` and `singleton()` for fluent registration after construction
- Typed `make()` and non-null `getOrFail()`
- Runtime parameters in `make()` and `resolve()`, overriding constructor arguments by name
- Named/scalar contextual bindings: `when(X)->needs('$paramName')->give(...)`
- The container is passed into binding closures, so factories can compose from other services
- Service tagging: `tag()` groups ids under a label, `tagged()` resolves them lazily
- Conditional registration: `bound()`, `bindIf()`, `singletonIf()`
- `afterResolving()` hooks
- `ArrayAccess` on the container
- Compiled constructor plans: `compile()`, `writeCompiledCache()`, `loadCompiledCache()`

### Fixed

- Stop sharing child instances between transient resolutions; dependencies are rebuilt per resolution

### Performance

- Memoize `#[Inject]` lookups on the recursive resolve path
- Short-circuit contextual-binding resolution when none are registered

## [0.9.0](https://github.com/gacela-project/container/compare/0.8.1...0.9.0) - 2026-07-19

### Changed

- Narrow the return type of `ContainerInterface::factory()` and `protect()` from `object` to `Closure`

## [0.8.1](https://github.com/gacela-project/container/compare/0.8.0...0.8.1) - 2026-06-05

### Fixed

- PHP 8.5 compatibility: use `SplObjectStorage::offsetSet()`/`offsetUnset()` instead of the deprecated `attach()`/`detach()`

## [0.8.0](https://github.com/gacela-project/container/compare/0.7.0...0.8.0) - 2025-11-08

### Added

- Attributes: `#[Inject]`, `#[Singleton]`, `#[Factory]`
- Contextual bindings via `when()->needs()->give()`, so the same interface can resolve differently per requesting class
- `alias()` for alternative service names
- Introspection: `getStats()`, `getRegisteredServices()`, `isFactory()`, `isFrozen()`, `getBindings()`, `getDependencyTree()`
- `warmUp()` to pre-resolve dependencies

### Fixed

- Constructor caching keyed on the concrete class name instead of the interface name

### Performance

- Cache attribute reflection, alias resolution, constructor lookups, `class_exists()`/`interface_exists()`, and `ReflectionClass` instances
- `callableKey()` uses `spl_object_id()` instead of `md5` + `var_export`

### Changed

- Error messages include the resolution chain and fuzzy-matched name suggestions for typos
- Circular dependencies are detected and reported explicitly
- Extract `AliasRegistry`, `FactoryManager`, `InstanceRegistry`, `DependencyCacheManager`, `BindingResolver` and `DependencyTreeAnalyzer` out of `Container`
- Add generic type annotations for static analysis

## [0.7.0](https://github.com/gacela-project/container/compare/0.6.1...0.7.0) - 2025-08-02

### Fixed

- Factory services

### Performance

- Avoid reflection on the resolve path; cache `ReflectionClass` instances

## [0.6.1](https://github.com/gacela-project/container/compare/0.6.0...0.6.1) - 2024-07-06

### Changed

- Support `psr/container` `>=1.1`

## [0.6.0](https://github.com/gacela-project/container/compare/0.5.1...0.6.0) - 2023-12-21

### Changed

- ⚠ Require PHP >= 8.1

## [0.5.1](https://github.com/gacela-project/container/compare/0.5.0...0.5.1) - 2023-06-24

### Changed

- Improve the error message when no concrete bound class is found

## [0.5.0](https://github.com/gacela-project/container/compare/0.4.0...0.5.0) - 2023-05-19

### Added

- `resolve(callable)` on `ContainerInterface`

### Changed

- Accept `Closure|string` in `getParametersToResolve()`

## [0.4.0](https://github.com/gacela-project/container/compare/0.3.0...0.4.0) - 2023-04-27

### Added

- `set()`, `factory()`, `extend()`, `remove()`, `protect()`

### Changed

- Remove `final` from `Container` to allow decorating it

## [0.3.0](https://github.com/gacela-project/container/compare/0.1.0...0.3.0) - 2023-04-24

### Added

- [PSR-11](https://www.php-fig.org/psr/psr-11/) support

### Changed

- ⚠ Rename `InstanceCreator` to `Container`

### Removed

- ⚠ `createByClassName()` — use `get()`

## [0.1.0](https://github.com/gacela-project/container/releases/tag/0.1.0) - 2023-03-11

Initial release, extracted from `gacela-project/gacela`.
