# Comment audit — `src/`, `tests/`, `benchmarks/`

Surveyed every comment token in 41 `src/` files, 154 `tests/` files and 15
`benchmarks/` files (tokeniser pass, not grep, so nothing inside strings was
counted).

## Volume

| Area | Files | Docblocks (prose / annotation-only) | Inline `//` blocks (lines) |
|---|---|---|---|
| `src/` | 41 | 499 (260 / 239) | 99 (286) |
| `tests/` | 154 | 171 (133 / 38) | 151 (280) |
| `benchmarks/` | 15 | 22 (19 / 3) | 5 (19) |

## Verdict

The density is high and the quality is high. Of ~600 comment blocks in `src/`,
**13 needed work** — 2.2%. The repo's comments overwhelmingly record *why* a
branch exists, what was measured, and what was tried and rejected; that is the
most valuable documentation in the project and none of it was touched.

## Classification

### 1. Functional annotations — 239 in `src/`, untouched by policy

`@var`, `@param`, `@return`, `@psalm-*`, `@phpstan-*`, `@template`,
`@infection-ignore-all`, `@api`, `@internal`, `@deprecated`. Removing any of
these breaks a tool. Not commentary.

Every `@param` carries a real description or none at all — no
`@param string $id The id` padding anywhere in the tree.

### 2. High-value rationale — the bulk of the remaining ~360 blocks, all kept

Measured performance rationale:

- `src/Container/DependencyCacheManager.php:439-448` — why the builder is read
  inline rather than through `argBuilderFor()`, with the ~1μs figure and #181.
- `src/Container/DependencyResolver.php:259-290` — why generated builders exist
  and why the guard is structural, plus the `@infection-ignore-all` paragraph
  explaining that every branch is fail-safe so mutants are unobservable.
- `src/Container/DependencyTreeAnalyzer.php:87-92` — subtree sharing, with the
  measured "25 classes, 318k nodes, 150ms" without it.
- `benchmarks/ContainerBench.php:49-63` — why every subject is gated, the ~20%
  noise floor measured on the shared runner, and the 7% budget that was tried
  and removed.

Deliberate refusals:

- `src/Container/ClassSource.php:33-35` — why `warmUp()` deliberately does not
  accept a `ClassSource`.
- `src/Container/DependencyResolver.php:617-619`, `629-632` — why a bound class
  and a scalar parameter are refused a builder.
- `src/Container/DefinitionLoader.php:160-168` — why YAML is not a dependency.
- `src/Container/Console/Cli.php:33-36` — why there is no console framework.

Lifetime and ordering:

- `src/Container/FactoryManager.php:24-36` — why the marks are a `WeakMap`, and
  what `SplObjectStorage` retained.
- `src/Container/DependencyResolver.php:885-888` — why the ghost initializer
  captures the container strongly and never reads it.
- `src/Container/DependencyResolver.php:132-137`, `140-147` — why `$hasParent`
  and `$supportsLazyObjects` are hoisted into fields.
- `src/Container/Container.php:141-143` — why the self-reference is weak (#149).

BC notes:

- `src/Container/FullContainerInterface.php:7-13` and
  `src/Container/Container.php:33-35` — why the deprecated interface still
  exists and that it goes at 3.0.
- `src/Container/ContainerStats.php:31-38` — why `processMemoryBytes` is the odd
  field out, including the 1.x name that invited the wrong reading.

In `tests/`, the dominant form is regression provenance — "this used to be
swallowed", "the per-instance cache this replaced stored the negative". That
framing is the *point* of the test and was left alone
(`tests/Unit/InstantiabilityCacheTest.php:166-169`,
`tests/Unit/StringBindingIsNeverInvokedTest.php:31-32`,
`tests/Unit/ByteFormatterTest.php:11-16`).

### 3. Stale in-motion narration — 6 in `src/`, rewritten

| Location | Problem |
|---|---|
| `src/Container/ClassSource.php:25-28` | "Every compile entry point **used to** take a `list<class-string>`" — they still do; line 30 says so three lines later |
| `src/Container/InstanceRegistry.php:77-78` | "what `method_exists()` **used to** prove inline — and proved again on every read" |
| `src/Container/CompiledCacheWriter.php:32` | "opcache maps it exactly **as before**" — before an envelope format a new reader never saw |
| `src/Container/Container.php:817` | "Any other id matches exactly, **as before**" — before `byType` matching existed |
| `src/Container/Container.php:1346` | "collected … exactly as it was **before its parent kept a handle** at all" |
| `src/Container/DependencyCacheManager.php:371` | "Don't cache dependencies for factory classes to ensure fresh instances" — **wrong**: nothing skips a dependency cache. Dependencies are rebuilt per resolution for every class (class docblock, lines 32-37); the branch only skips the `resolvedKeys` bookkeeping that feeds `cachedDependencies` in `stats()` |

All six rewritten to state current behaviour, same length or shorter.

### 4. Restates the code — 3 in `src/`, removed

- `src/Container/AliasRegistry.php:41` — `// Clear cached resolutions when
  aliases change` above `$this->resolvedCache = [];` in `add()`.
- `src/Container/DependencyResolver.php:800` — `// It's a class string - use it
  instead of the interface` above a `/** @var class-string */` and an
  assignment.
- `src/Container/DependencyTreeAnalyzer.php:121` — `// Resolve binding if it's
  an interface` above `resolveType()`.

Borderline cases deliberately **kept**, because each justifies a discarded
result or a non-obvious side effect:

- `src/Container/DependencyCacheManager.php:310` — says why `resolveDependencies()`
  is called for effect inside `warmUp()`.
- `src/Container/DependencyResolver.php:225` — says the build stack exists for
  contextual bindings, which the push does not.
- `src/Container/Container.php:538` — says why an object concrete needs no
  `markAsSingleton()`.

### 5. Formatting filler — 4, fixed

Doubled empty docblock lines left behind by earlier edits:
`src/Container/Container.php:483`, `:573`, `:1003`, `:1051`.

### 6. Stubs, TODO/FIXME, commented-out code — 0

None anywhere in `src/`, `tests/` or `benchmarks/`.

### 7. `tests/` and `benchmarks/` filler — 5, fixed

- `tests/Unit/ContainerStatsTest.php:90`, `:94` — `// Before warmup` /
  `// After warmup` above `$statsBefore` and `$container->warmUp(...)`. Removed.
- `tests/Unit/FuzzyMatcherTest.php:72` — `// Exact match should be first` above
  `assertSame('UserService', $result[0])`. Removed.
- `tests/Unit/ClassContainerTest.php:127-128` — "As result, a 'new Person()'
  will be resolved." Rewritten as one grammatical sentence.
- `tests/Fake/ServiceWithScalarDependency.php:11` — `// No default - will cause
  error`. Rewritten to state the property rather than a vague outcome.

`benchmarks/` needed nothing.
