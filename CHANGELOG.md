# Changelog

Notable changes to `gacela-project/container`.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning: [Semantic Versioning](https://semver.org/) from 1.0.0 — see the
[BC policy](docs/backward-compatibility.md).

**Breaking changes are marked ⚠.**

## Unreleased

### Added

- `#[Lazy]` defers construction until an instance is first used, via PHP 8.4 native lazy objects. Returns a real instance of the class rather than a proxy subclass. On PHP 8.3 the class is constructed eagerly instead, which is unobservable apart from the timing

### Performance

- Read `#[Singleton]`, `#[Factory]` and `#[Lazy]` in a single memoized reflection pass per class instead of one concatenated cache key per attribute. Resolution is measurably faster than before `#[Lazy]` existed

### Internal

- Extract byte formatting out of `Container` so it can be tested directly; raises the Infection gate to 87
- Audit the escaped mutants in `DependencyResolver`, `DependencyCacheManager`, `FuzzyMatcher` and `DependencyTreeAnalyzer`; add 16 tests for the killable ones and raise the Infection gate from 80 to 83

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
