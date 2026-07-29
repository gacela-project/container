# Changelog

Notable changes to `gacela-project/container`.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning: [Semantic Versioning](https://semver.org/) from 1.0.0 — see the
[BC policy](docs/backward-compatibility.md).

**Breaking changes are marked ⚠.**

## Unreleased

### Performance

- A container built a `TagRegistry` and a `DependencyTreeAnalyzer` whether or not it ever grouped a service under a tag or was asked for a dependency tree, and `createScope()` paid for both per scope — the operation whose whole point is being cheap enough to run per request. Both are built on first use now: creating a scope is 10.5% faster and a cold container resolving a four-level chain 3.4% faster, for 1% less peak memory. `FactoryManager` stays eager deliberately, since `get()` reaches it for any stored instance
- `resolve()` re-reflected its callable on every call: every other reflection result in the container is memoized and this one was not, so invoking a callable cost more in `ReflectionFunction` than in the resolution it was feeding. Parameter plans are memoized now — by `Class::method` where the callable has a name, so two instances of a class share one plan, and by the closure itself through a `WeakMap` where it does not, so a plan is released with the closure that needed it. The `Closure::fromCallable()` conversion moved to the miss path, since a cached plan needs neither reflection nor a closure. Invoking a one-argument method through the container is 27% faster
- Whether a class carries `#[Singleton]` or `#[Factory]` was cached per container, so every container reflected every class it resolved to re-derive an answer that belongs to the class definition and cannot change while the process runs — the tax `PlanCache` removed on the plan axis, still being paid on this one. The verdict is now shared across containers, and cleared by `resetStaticCaches()` like the other class-shape memos. Cold resolution of a four-level chain is 6.0% faster and ten sibling containers resolving the same chain hold 9.4% less peak memory, rising to 16.9% when they also share a plan cache

- Three caches answered "can this class be instantiated?", and the one `has()` reached built a `ReflectionClass` of its own for it — so a cold `has()` on an unregistered class reflected a class the plan registry either already held or was about to describe, and `lazy()` paid the same through its target check. That cache was also per container, so siblings each rebuilt it. The question is now answered once per class per process, off the class plan that `get()` needs anyway: `has()` leaves behind the plan rather than a throwaway reflection, and a sibling sharing a `PlanCache` reflects nothing at all
- Reading a stored instance ran `method_exists($instance, '__invoke')` every time, to answer a question the instance's class settles once. The answer is memoized per class now, shared across containers and cleared by `resetStaticCaches()` like the other class-shape memos: reading a `set()` instance is 6.4% faster

### Fixed

- A binding was tested with `is_callable()` before `is_string()` in all three places one is read — including the lookup that runs once per constructor parameter of every node of every graph. On a class-string that is a function-table lookup answering false; on a class-string that a defined function happens to share a name with it answers *true*, and the binding was invoked instead of instantiated — the wrong object, silently, with nothing raised anywhere. Strings are settled as class names now and never reach the function table. Closure, invokable-object and instance bindings are unchanged

- `has()` remembered a negative: an id that was not loadable the first time it was asked about kept answering `false` for the rest of that container's life, contradicting the "only positives are cached" guarantee [`resetStaticCaches()`](docs/performance.md#process-global-caches) documents. A class declared after the first probe — generated code, a `class_alias()`, a test suite that declares as it goes — is now seen. The cost is that `has()` on an id that is not a class re-runs `class_exists()` each call rather than answering from a stale memo, measured at 0.124μs → 0.139μs; `has()` on an autowirable class is unchanged
- A `bind()` or `singleton()` for a class that has a generated factory was silently ignored: `useCompiledFactories()` was consulted before the bindings, so `bind(Mailer::class, LoggingMailer::class)` kept handing back the generated `new Mailer`, and `singleton(Mailer::class)` built a fresh instance per `get()` while the application believed the service was shared. The generator refuses to emit anything for a bound class, so the file always agreed with the container it was written *from* — it just could not speak for the one it was installed into, in either call order. Registration on the receiving container now outranks a generated expression, and a container with no factory map installed pays nothing for the check. See [performance](docs/performance.md#registration-still-wins)
- One of the benchmark assertions #83 added was attached to `setUpColdFactories()` — a *setup* method, so it asserted nothing, and the subject it prepares was the fastest documented path with no gate at all. It now sits on `benchColdResolveDeepChainGenerated`, verified by injecting a regression and watching the run exit non-zero. `benchColdResolveDeepChainCompiled` and `benchColdResolveAcrossSiblingsSharingPlans` are gated too, so every performance figure in the docs has an assertion behind it
- Benchmark iterations deviating 5% or more from the mean of their set are re-run, which is what makes those assertions able to fire: the worst subject measured ±40% relative standard deviation on the first `phpbench` invocation of a session against ±8% on the second, and the CI job stored its baseline on exactly that first invocation — inflating the baseline, flattering every candidate, and biasing the comparison towards missing regressions rather than reporting them. CI now warms the caches with a discarded run first, and the whole suite reports under ±3.2%

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
