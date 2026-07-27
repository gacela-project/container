# Backward Compatibility

[← Back to index](../README.md#documentation)

From 1.0 onward this package follows [Semantic Versioning](https://semver.org).
This page states exactly what that promise covers — and, just as importantly,
what it does not.

## What is covered

Only classes marked `@api`. Every class in `src/` carries either `@api` or
`@internal`, so you can check any file directly.

| Covered (`@api`) | |
|---|---|
| `Container` | the container itself, `final` |
| `ContainerInterface` | the contract to type-hint |
| `ContextualBindingBuilder` | returned by `when()` |
| `CompilationReport`, `CompilationSkipReason` | returned by `compileReport()` |
| `Attribute\Inject`, `Attribute\Singleton`, `Attribute\Factory`, `Attribute\Lazy` | |
| `Exception\ContainerException` | |
| `Exception\CircularDependencyException` | |
| `Exception\DependencyNotFoundException` | |
| `Exception\DependencyInvalidArgumentException` | |

For these, within 1.x:

- Public method signatures will not change, including **parameter names** —
  named arguments are safe to use.
- `Container::__construct()` keeps its three optional parameters, in order.
- No method will be added to `ContainerInterface`. That is why the interface was
  brought to its full shape before 1.0. Anything that would otherwise belong
  there lands on `Container` instead until 2.0 — currently `stats()`,
  `createScope()`, `provides()`, `lazy()`, `load()` and `loadFile()`.
- Exception **classes** and the PSR-11 interfaces they implement are stable.

## What is not covered

**`@internal` classes.** `AliasRegistry`, `BindingResolver`, `DefinitionLoader`,
`DependencyCacheManager`, `DependencyResolver`, `DependencyTreeAnalyzer`,
`FactoryManager`, `FuzzyMatcher`, `InstanceRegistry`, `PlanRegistry`, and
`TagRegistry` are implementation details. They may change signature, behaviour,
or be deleted in **any** release, including a patch. Do not import them.

**Exception messages.** Only the class is stable. Messages carry fuzzy-match
suggestions and resolution chains that should stay free to improve — never parse
or assert on them.

**The array returned by `getStats()`.** Debug output. Its keys and value formats
may change at any time. Do not build logic on it — use `stats()` instead, which
returns a `ContainerStats` whose properties *are* covered here. Adding a property
to a readonly object is additive and safe; adding a key to an array is not, which
is the entire reason for this carve-out.

**The compiled cache file format.** The file written by `writeCompiledCache()`
and `writeCompiledFactories()` is a build artifact tied to the exact version
that produced it. Regenerate it on every upgrade; never commit it and never
hand-edit it. It carries a format marker, and a file this version cannot read is
refused with a `ContainerException` rather than half-understood. Read it back
with `loadCompiledCache()` / `loadCompiledFactories()` — a raw `require` sees
the envelope, not the map, and skips the
[staleness check](performance.md#staleness).

**Behaviour under `@internal` inputs**, e.g. passing untrusted user input as a
service id. See [SECURITY.md](../.github/SECURITY.md).

## What counts as a breaking change

Requiring a new major version:

- Adding a method to `ContainerInterface`
- Changing a public signature or parameter name on an `@api` class
- Raising the minimum PHP version
- Changing documented resolution behaviour

Not requiring one:

- Anything in an `@internal` class
- New optional parameters at the end of a signature
- Improved exception messages
- Performance work with no observable behaviour change

## PHP support

1.x requires **PHP >= 8.3**. The floor will not rise within 1.x. New PHP
versions are added to CI as they are released.

## Related

- [API Reference](api-reference.md) — the full `@api` / `@internal` breakdown
- [UPGRADE.md](../UPGRADE.md) — migrating from 0.10.0
