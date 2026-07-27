# Changelog

Notable changes to `gacela-project/container`.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning: [Semantic Versioning](https://semver.org/) from 1.0.0 — see the
[BC policy](docs/backward-compatibility.md).

**Breaking changes are marked ⚠.**

## Unreleased

### Added

- `Container::compileReport()`, turning the generator's silent conservatism into an answer a build can assert on. It returns a `CompilationReport` — `compiled()`, `skipped()`, `reasonFor()`, `explain()` — where the reason is a `CompilationSkipReason` case per branch that can refuse a class (bound, `lazy()`-registered, not instantiable, lifetime attribute, `#[Inject]` property or parameter, scalar or untyped parameter, dependency cycle, no plan, blocked dependency) and the explanation names the specific parameter or dependency. Reasons are recorded by the branches that make the decision rather than by a parallel list, so `compiled()` is exactly what `writeCompiledFactories()` returns for the same input and cannot drift from it. Nothing is written, `compile()`/`writeCompiledCache()`/`writeCompiledFactories()` are unchanged, and there is no cost on the resolution path. `beforeCompile()` was considered and dropped: compilation is caller-triggered, so calling `bind()` before `compile()` already covers it

- `Container::load()` and `Container::loadFile()`, registering services from data instead of code. An entry is a class-string, or an array with at most one of `singleton`, `value`, `factory` or `alias` plus an optional `tags` list; `loadFile()` reads a `.php` file returning an array or a `.json` file. Nothing new happens at resolution time — each entry performs the registration call it stands for, so laziness, freezing, contextual bindings and scopes behave exactly as the imperative equivalent does. Later keys override earlier ones, which makes per-environment layering a matter of load order, while `tags` accumulate the way `tag()` does. Every failure — unknown key, two binding keys in one entry, a wrong value type, a non-string id, a missing/unreadable/unsupported file, a `.php` file returning no array, invalid JSON — raises a `ContainerException` naming the id and the file. There is still no YAML or XML parser here and `psr/container` remains the only runtime dependency: parse it yourself and `load()` the array. See [definitions](docs/definitions.md)

- `Container::lazy()`, deferring construction without `#[Lazy]` on the class. `lazy(Vendor::class)` makes a class you do not own lazy, `lazy(Ifc::class, Impl::class)` binds an abstract to a deferred concrete, and `lazy(Impl::class, fn (Container $c) => …)` defers a *closure* binding — which `bind()`/`singleton()` always ran the moment the id resolved. The first two forms produce the same native lazy ghost as the attribute; the closure form produces a native lazy proxy, since the closure rather than the constructor makes the instance. Either way it is a real instance of the class — no proxy subclass, no new dependency. The target must be a concrete, instantiable class, and anything else throws a `ContainerException` naming what to do instead. Registering both `#[Lazy]` and `lazy()` is not an error; `singleton()` combines with it exactly as `#[Singleton] #[Lazy]` does; a scope inherits the registrations its parent had when the scope was created. Like `stats()`, `createScope()` and `provides()`, it lives on `Container` rather than `ContainerInterface`, which 1.x promises not to extend

### Fixed

- Laziness stopped at the top of a resolution: `#[Lazy]` was only consulted when the class was the id being resolved, so an expensive lazy service injected into another class's constructor was built eagerly along with its whole subtree — the case the attribute exists for. Nested resolution now honours it, for `#[Lazy]` and `lazy()` alike

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
