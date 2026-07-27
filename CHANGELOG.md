# Changelog

Notable changes to `gacela-project/container`.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning: [Semantic Versioning](https://semver.org/) from 1.0.0 — see the
[BC policy](docs/backward-compatibility.md).

**Breaking changes are marked ⚠.**

## Unreleased

### Added

- `#[Inject]` now targets properties as well as constructor parameters, for classes whose constructor is not yours to change. Private, protected and inherited properties are supported; static properties are ignored, and a promoted parameter is still injected only by the constructor. Constructor injection remains the default — see [attributes](docs/attributes.md#on-properties)
- A cycle reached through an injected property still raises `CircularDependencyException`. Property injection runs inside the same resolution stack, so it is deliberately not an escape hatch for the diagnostic
- `readonly`, untyped and scalar-typed `#[Inject]` properties fail with a `DependencyInvalidArgumentException` naming the property and what to do instead, rather than a raw PHP error

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
