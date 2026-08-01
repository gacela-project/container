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
| `FullContainerInterface` | everything `Container` does, as a contract (1.5) |
| `ContextualBindingBuilder` | returned by `when()` |
| `CompilationReport`, `CompilationSkipReason` | returned by `compileReport()` |
| `ValidationReport`, `ValidationIssue`, `ValidationProblem` | returned by `validate()` |
| `DependencyNode` | returned by `dependencyGraph()` |
| `ClassSource` | where the compile calls find their classes |
| `PlanCache` | one plan cache shared between unrelated containers |
| `Attribute\Inject`, `Attribute\Singleton`, `Attribute\Factory`, `Attribute\Lazy` | |
| `Exception\ContainerException` | |
| `Exception\CircularDependencyException` | |
| `Exception\DependencyNotFoundException` | |
| `Exception\DependencyInvalidArgumentException` | |

For these, within 1.x:

- Public method signatures will not change, including **parameter names** —
  named arguments are safe to use.
- `Container::__construct()` keeps its three optional parameters, in order.
- **`ContainerInterface` declares the whole surface** as of 2.0, and nothing
  will be added to it within 2.x. That freeze is the same promise 1.x made; what
  changed is that the interface now covers everything `Container` does, so
  depending on it no longer costs you features.
- `FullContainerInterface` is a **deprecated** empty alias of it, kept so a 1.5
  type-hint keeps compiling. It is removed at 3.0.
- Implementing either interface is what a new method would break, which is why
  additions wait for a major. Callers are never affected.
- Exception **classes** and the PSR-11 interfaces they implement are stable.

## What is not covered

**`@internal` classes.** `AliasRegistry`, `BindingResolver`, `ContainerCompiler`,
`ContainerValidator`, `DefinitionLoader`, `DependencyCacheManager`,
`DependencyResolver`, `DependencyTreeAnalyzer`, `FactoryManager`,
`FuzzyMatcher`, `InstanceRegistry`, `PlanRegistry`, `TagRegistry` and everything
under `Console\` — including the `gacela-container` CLI, which is a build tool
rather than API surface — are implementation details. They may change signature, behaviour,
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
