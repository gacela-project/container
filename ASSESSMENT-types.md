# Type inventory and consolidation assessment

Scope: every `@psalm-type`, `@psalm-import-type` and inline array shape under
`src/`. PHP has no first-class structural type, so "types" here means psalm
aliases and the docblock shapes that stand in for them.

Baseline before any change: Psalm **No errors**, inference **99.1546%**,
**792** tests green, PHPStan level max clean.

## 1. Declared aliases

Eleven aliases, in three owners.

| Alias | Declared at | Expansion | Imported by | Verdict |
|---|---|---|---|---|
| `Binding` | `ContainerInterface.php:15` | `class-string\|callable\|object` | `Container` | correct owner — appears in `@api` signatures (`ContainerInterface.php:79,86,98,105`) |
| `BindingsMap` | `ContainerInterface.php:16` | `array<class-string, Binding>` | `Container`, `ContainerCompiler`, `DependencyResolver`, `BindingResolver`, `DependencyCacheManager` | correct owner |
| `ContextualBindingsMap` | `ContainerInterface.php:17` | `array<string, array<string, mixed>>` | `Container`, `DependencyResolver`, `ContextualBindingBuilder`, `ContainerValidator`, `DependencyCacheManager` | correct owner (see §5) |
| `StatsArray` | `ContainerInterface.php:18-25` | 6-key shape | *nobody* | **duplicated inline** — see §3.2 |
| `ParamPlan` | `DependencyResolver.php:36` | 8-key shape | *nobody* | **duplicated inline in 2 files** — see §3.1 |
| `PropPlan` | `DependencyResolver.php:37` | 7-key shape | — | correct owner, single-file use, no duplication |
| `MethodPlan` | `DependencyResolver.php:38` | 5-key shape | — | ditto |
| `ClassPlan` | `DependencyResolver.php:39` | 4-key shape | — | ditto |
| `StoredClassPlan` | `DependencyResolver.php:40` | `ClassPlan` with `props`/`methods` optional | — | ditto; the on-disk narrowing of `ClassPlan`, deliberately distinct |
| `CompiledPlans` | `DependencyResolver.php:41` | `array<class-string, StoredClassPlan>` | `Container`, `ContainerCompiler`, `ContainerValidator`, `CompiledCacheWriter`, `DependencyCacheManager`, `PlanCache`, `PlanRegistry` | correct owner, best-used alias in the codebase |
| `FileStamp` | `CacheStamp.php:30` | `array{string, int, int}` | `CompiledCacheWriter` | correct owner |

Nineteen `@psalm-import-type` lines across ten files. **All nineteen are used.**
There are no dead imports to remove.

## 2. What is wrong

Four of the eleven aliases carry the load. `StatsArray` is imported by nobody
while its expansion is re-typed by hand, and `ParamPlan` — the single most
widely *passed* shape in the library — is imported by nobody while being
re-typed by hand in the two classes that consume it. The aliases exist; the
discipline of importing them stops at the file that declares them.

## 3. Duplicates found

### 3.1 `ParamPlan` re-spelled in full, in two files

`DependencyResolver` hands `list<ParamPlan>` to both consumers, and both
re-declare the eight keys character by character rather than importing:

- `ContainerCompiler.php:204` — `argumentFor()`, full 8-key expansion
- `ContainerValidator.php:136` — `checkParameter()`, full 8-key expansion

Both files *already* `@psalm-import-type CompiledPlans from DependencyResolver`
(`ContainerCompiler.php:32`, `ContainerValidator.php:35`), so the import edge
they need is already there — only the alias is missing from it. Three copies of
an eight-key shape drift the moment a ninth key lands, and psalm will not warn:
the copies are structurally valid, merely stale.

### 3.2 `ParamPlan` re-spelled as a widened subset

`ContainerValidator.php:218`:

```php
@param array{name: string, declaringClass: string|null, ...} $param
```

A near-duplicate rather than a duplicate: the same shape, opened with `...` and
cut to the two keys the body reads. Its only caller is
`ContainerValidator.php:143`, which passes the very `$param` typed as the full
shape at `:136`. The widening buys nothing and hides that this is a `ParamPlan`.

### 3.3 `StatsArray` re-spelled in full

`Container.php:1253-1260` restates the exact 6-key body of
`ContainerInterface.php:18-25` on `getStats()`. `Container` already imports
three aliases from `ContainerInterface` (`:26-28`) — `StatsArray` was simply
left out of the list. The interface and the implementation disagreeing about
`getStats()` is precisely the failure this alias was written to prevent.

### 3.4 An unnamed shared shape: the compiled-factories map

`array<class-string, callable(): object>` appears **seven times in three
files**, with no alias at all:

- `ContainerInterface.php:381` — `useCompiledFactories()`, `@api`
- `Container.php:95` (property), `:184`, `:495`, `:1397`
- `CompiledCacheWriter.php:109`, `:113`

It crosses the public API boundary and is the return type of
`loadCompiledFactories()`. It is exactly the kind of thing `BindingsMap` is,
and it sits next to `BindingsMap` at every call site — it just never got a name.

### 3.5 Shapes duplicated inside one file

| Shape | Sites | Concept |
|---|---|---|
| `array{id: string, byType: bool, callback: Closure}` | `Container.php:83`, `Container.php:1519` | one `afterResolving()` hook |
| `array{singleton: bool, factory: bool}` | `DependencyCacheManager.php:54`, `DependencyCacheManager.php:521` | a class's memoized lifetime attributes |
| `array<string, string|bool>` | `Console/Cli.php:103,148,173,250,273,304,336` | parsed argv options |

Same owner, same file, no import needed — a local `@psalm-type` names the
concept and removes the copies. The `Cli` one is seven copies of a shape whose
meaning ("the parsed options") is nowhere written down.

## 4. Deliberately left alone

- **`array<class-string, true>`** — `ContainerCompiler.php:54`,
  `DependencyResolver.php:69,156,359`, `DependencyTreeAnalyzer.php:66`,
  `DependencyCacheManager.php:89,98,252`. Identical spelling, **unrelated
  meanings**: lazy classes, a recursion guard, open ancestors on a path, forced
  singletons. One alias would assert a kinship that does not exist and would
  let a lazy-class map be passed where a cycle guard is wanted. Coincidence of
  spelling is not shared type.
- **`array<string, mixed>`, `array<array-key, mixed>`** — the generic bag,
  ~20 sites. No shared meaning to name.
- **`array<string, list<Closure>>`** (`Container.php:125`,
  `FactoryManager.php:47`) — two sites, a plain generic rather than a shape,
  and legible inline. An alias here would cost an import edge to buy nothing.
- **`array{bool, mixed}`** (`DependencyResolver.php:743`) — one site, already
  documented inline as `[matched, value]`.
- **`list<array{0: int, 1: string, 2: int}|string>`** (`ClassSource.php:229`) —
  one site, and it is PHP's own `token_get_all()` shape, not ours to name.
- **`PropPlan` / `MethodPlan` / `ClassPlan` / `StoredClassPlan`** — declared and
  used in `DependencyResolver` only. Correct owner, zero duplication.

## 5. Ownership question, resolved as no-change

`ContextualBindingsMap` is declared on `ContainerInterface` but is used by no
signature *on* `ContainerInterface` — only by five other classes. On the letter
of "declared in the wrong owner" it is misplaced.

It stays. Every one of the five consumers already imports from
`ContainerInterface`, which makes it the shared root in practice; the honest
alternative owner is an `@internal` class, and pointing `@api` docblocks at
`@internal` types inverts the dependency the BC policy rests on. Moving it is
churn with a downside and no upside.

## 6. Not done, on purpose

No alias becomes a runtime class. `ParamPlan` is built once per constructor
parameter on the resolution path and read on every `get()`; promoting it to an
object trades a documented shape for an allocation in the hot path, which is
the opposite of what this library optimises for. Aliases are free — they exist
only in docblocks.

No new abstraction layer, no new files. Every consolidation below is either an
import line replacing a copy, or a `@psalm-type` next to the aliases that are
already there.

## 7. Changes made

| # | Change | Files |
|---|---|---|
| 1 | import `ParamPlan`, drop 2 full copies | `ContainerCompiler.php`, `ContainerValidator.php` |
| 2 | tighten the `...` subset to `ParamPlan` | `ContainerValidator.php:218` |
| 3 | import `StatsArray`, drop the copy | `Container.php` |
| 4 | name `FactoriesMap` on `ContainerInterface`, import it | `ContainerInterface.php`, `Container.php`, `CompiledCacheWriter.php` |
| 5 | local `@psalm-type ResolvedHook` | `Container.php` |
| 6 | local `@psalm-type LifetimeFlags` | `DependencyCacheManager.php` |
| 7 | local `@psalm-type CliOptions` | `Console/Cli.php` |

Counted by hand-written spelling: `ParamPlan` 3, `StatsArray` 1, `FactoriesMap`
7, `ResolvedHook` 2, `LifetimeFlags` 2, `CliOptions` 7 — **22 inline spellings
replaced by an alias reference**, against **4 new alias declarations**. Every
edit is inside a docblock; no statement changed, so there is nothing here for
the hot path to notice.

### Verification

| Tool | Before | After |
|---|---|---|
| PHPUnit | 792 tests, 1487 assertions, OK | 792 tests, 1487 assertions, OK |
| PHPStan (level max) | No errors | No errors |
| Psalm | No errors | No errors |
| Psalm inference | 99.1546% | **99.1546%** |
| php-cs-fixer | — | fixed 0 of 195 files |

The inference figure is unchanged to four decimals, which is the expected
result: an alias and its expansion are the same type to psalm, so consolidating
them can only move that number if a replacement was not in fact equivalent.
Line 3.2 was the one edit that changed a resolved type — `ParamPlan` is
strictly narrower than the `...`-opened subset it replaced — and it narrowed
without new errors, confirming the caller really was passing a full `ParamPlan`.

### Backward compatibility

Nothing here changes a resolved type. An alias expands to the identical string
it replaced, so every `@api` signature — `Container::getStats()`,
`ContainerInterface::useCompiledFactories()` — types exactly as before to any
consumer. `FactoriesMap` is *added* to `ContainerInterface`; adding a
`@psalm-type` is not adding a method, and it follows the pattern the interface
already sets with `Binding`, `BindingsMap` and `StatsArray`. No `@api` type is
removed or renamed.
