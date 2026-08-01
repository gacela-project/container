# Legacy / deprecated / fallback audit

Baseline: `gacela-project/container` at **2.0.1**, PHP floor **8.3**, BC policy in
[`docs/backward-compatibility.md`](docs/backward-compatibility.md).

The rule applied throughout: **`@api` surface is removed at the next major, never
opportunistically. `@internal` is not BC-covered and dead code in it goes now.**
Every verdict below cites the commit that introduced the item, because this
repository's commit messages record intent well enough to tell a leftover from a
deliberate guard.

---

## Verdicts

| # | Item | Kind | Verdict | Evidence |
|---|---|---|---|---|
| 1 | `AliasRegistry::has()` | dead code (`@internal`) | **remove now** | Added by `90cc3fd` (2025-11-08, "extract class to reduce Container complexity") with **no caller in that commit**, and it never gained one. Zero references in `src/`; the only two callers anywhere were the two tests written for it. Psalm `findUnusedCode` flags it as `PossiblyUnusedMethod`. |
| 2 | `DependencyResolver::exportPlans()` | leftover second path (`@internal`) | **remove now** | Was the production export in `4f6e30e` — `DependencyCacheManager::exportCompiledPlans()` delegated to it. `ee58aa9` (#107) introduced `PlanRegistry` as shared state, and the manager has returned `$this->planRegistry->plans` directly ever since. The method has been an orphan accessor over the same array, kept alive by one test assertion. |
| 3 | `DefinitionLoader::load()` — two stacked docblocks | leftover | **remove now** | Two `/** … */` blocks sit back to back on one method. The first is the pre-`$onRegistered` version; PHP and both analysers only see the last, so it is invisible to every reader that matters — and the surviving one had lost `@param string\|null $file`. Merged into one accurate block. |
| 4 | `Container::loadFile()` — "YAML stays a userland concern — there is no parser here" | stale doc, now **false** | **remove now** | `0e26100` (#164) taught `DefinitionLoader::loadFile()` to dispatch `.yaml`/`.yml` to `symfony/yaml`, added the `suggest`, and updated `ContainerInterface::loadFile()` and `ContainerException::definitionFileUnsupported()`. `Container::loadFile()`'s own docblock was missed and still denied the feature existed. |
| 5 | `Container::loadFile()` — "On FullContainerInterface — see load()." | dangling cross-reference | **remove now** | Written by `31cdee4` (#154) when `load()`/`loadFile()` lived only on `FullContainerInterface`. `4c1c306` (#179) moved the whole surface onto `ContainerInterface`; `load()`'s matching note went with it, this one did not, and it now points at a note that no longer exists. |
| 6 | `Container::getStats()` — "Kept for the whole of 1.x." | stale version claim | **remove now** | From `5ee78fd` (#104), written while 1.x was current. The library is 2.0.1 and the BC doc schedules the method for removal at 3.0. Now says "for the whole of 2.x and removed at 3.0". |
| 7 | `Container::getDependencyTree()` — "on ContainerInterface, which 1.x does not change" | stale version claim | **remove now** | From `519a4ed` (#156). 1.x is over; the freeze that makes the sentence true is now 2.x's. |
| 8 | `ContainerInterface::getStats()` — "This method is replaced by it in 2.0" | stale / misleading | **remove now** | Reads as "already gone at 2.0". It is still on the interface at 2.0.1. Restated to match `docs/backward-compatibility.md`: superseded by `stats()`, removed at 3.0. |
| 9 | `Container::getStats()` re-declared the `StatsArray` shape inline | duplicated declaration | **remove now** | `ContainerInterface` already declares `@psalm-type StatsArray` and uses it for its own `@return`. Two hand-maintained copies of one six-key shape can drift silently. `Container` now imports it, like it already imports `Binding`, `BindingsMap`, `ContextualBindingsMap` and `CompiledPlans`. |
| 10 | `ContainerCompiler::argumentFor()` — `$this->explanations[$type] ?? 'reason unknown'` | unreachable fallback | **remove now** | `expressionFor()` returns `null` on exactly six paths and **every one of them goes through `skip()`**, which writes `reasons[$class]` and `explanations[$class]` together. By the time this line reads `explanations[$type]`, it is always set. Both analysers stay green without the default. |
| 11 | `Cli::printReport()` — `$explanations[$class] ?? ''` | unreachable fallback | **remove now** | Same invariant, and the comment directly above it already argues the point: *"the two are written together and keyed alike, and this way … there is no unreachable 'unknown' branch to defend."* It then defended one. The loop is driven off `reasons()`, whose keys are written in lockstep with `explanations()` in `ContainerCompiler::report()`. |
| 12 | `Cli::help()` — `USAGE` block listed only `compile` and `report` | stale help text | **remove now** | `72d6d54` (#166) added the `validate` command and updated the `COMMANDS` section beneath it, but not `USAGE`. The CLI advertised two of its three commands. |
| 13 | `.github/workflows/ci.yml` — `mutation-testing` on `actions/cache@v4` | stale CI pin | **remove now** | `a26e5fd` (#70, dependabot) bumped **four** occurrences from v4 to v6. `14b03f3` (#75, mutation testing) had branched earlier and merged after, re-introducing a fifth v4 that the bump had already retired. |
| 14 | `CallableKey::identify()` used `get_class($x)`, `nameOf()` used `$x::class` | two spellings of one operation | **remove now** | Same file, same question, two answers. `::class` is the newer form the rest of `src/` uses; `get_class` was the only `use function get_class;` in the library. |
| 15 | `empty($chain)` / `count($x) === 0` / `count($x) > 0` on arrays (4 sites) | pre-convention leftovers | **remove now** | All four date to Nov 2025 (`6ea8e50`, `b928e37` and siblings), before the codebase settled on `=== []` / `!== []` — which every one of the ~35 other array-emptiness checks in `src/` now uses. `empty()` was the last one in the library. |
| 16 | `FullContainerInterface` (the interface, `Container implements` it, the `@psalm-suppress DeprecatedInterface`) | deprecated `@api` | **scheduled for 3.0** | `4c1c306` (#179) made it an empty alias on purpose so a 1.5 type-hint keeps accepting a `Container`. `docs/backward-compatibility.md` and `docs/api-reference.md` both already say "removed at 3.0". Removing it now is a breaking change. |
| 17 | `ContainerInterface::getStats()` / `Container::getStats()` / `StatsArray` | superseded `@api` | **scheduled for 3.0** | Superseded by `stats(): ContainerStats` since `5ee78fd` (#104). Still public, still on the interface, still tested. The *array shape* is carved out of BC; the *method* is not. |
| 18 | `method_exists(ReflectionClass::class, 'newLazyGhost')`, `DependencyResolver::$supportsLazyObjects`, `$lazyObjectsAvailable` | runtime capability check | **keep, load-bearing** | Not a version guard for a dropped PHP — a probe for **PHP 8.4+ native lazy objects** against an 8.3 floor. The `tests` CI matrix runs 8.3, and `code-coverage` deliberately runs 8.4 with a comment explaining why. Removing it breaks the 8.3 leg. |
| 19 | `DependencyResolver::describeClass()` props/methods back-fill | forward-compat for on-disk caches | **keep, load-bearing** | Its own comment: a cache written before property injection has no `'props'`, one written before method injection has no `'methods'`, and trusting the file "would silently turn injection off in exactly the environment (production) where the cache is used". Deliberate, and the failure mode it prevents is silent. |
| 20 | `ContainerCompiler` — `($plan['props'] ?? [])`, `($plan['methods'] ?? [])` | same forward-compat | **keep, load-bearing** | `StoredClassPlan` declares both keys optional. `exportCompiledPlans()` returns the whole registry, including entries seeded from a compiled cache that the planner never re-described, so these two really can be absent here. |
| 21 | `Container::forClosures()` — `$this->selfReference->get() ?? $this` | live fallback | **keep, load-bearing** | The reference is weak by design (`31f1a60`, #157 — a strong back-pointer made every container a cycle). A dropped decorator makes `get()` return null and a closure still has to receive something. |
| 22 | `DependencyCacheManager::isInstantiable()` calls `class_exists()`, then `DependencyResolver::isInstantiable()` calls it again | apparent duplicate | **keep, load-bearing** | Two different jobs. The manager's guard stops it constructing a resolver for an unloadable class (and its comment explains the memo-before-`class_exists` ordering); the resolver's is its own entry guard — it is a public `@internal` entry point that tests call directly with a class that does not exist. |
| 23 | `DependencyResolver` — `$this->supportsLazyObjects && $this->isLazy(...)` at two call sites, where `isLazy()` re-tests the flag | apparent duplicate | **keep, deliberate** | The inline flag read is documented as a hot-path optimisation: *"on PHP 8.3 nothing is ever lazy, and this runs for every node of every graph"*. `refusesForLaziness()` is the third caller and has no pre-guard, so `isLazy()`'s own check is not redundant either. |
| 24 | `bin/gacela-container` — two-candidate autoloader probe | runtime fallback | **keep, load-bearing** | Covers "installed as a dependency" and "this repository / path repository". Both are real. |
| 25 | `ClassSource::locateClassmap()` — walk up to find `vendor/` | runtime fallback | **keep, load-bearing** | Same reason, and the comment says so: `vendor/gacela-project/container/src/Container` vs `src/Container`, without hardcoding either depth. |
| 26 | `CacheStamp::isCurrent(null) === true` | fallback | **keep, load-bearing** | A null stamp means "internal class, or no readable file" — treating it as stale would discard entries that no edit can invalidate. |
| 27 | `CompiledCacheWriter::FORMAT = 1` and the format-mismatch refusal | versioning, not legacy | **keep, load-bearing** | The BC doc names it: a file this version cannot read is refused with a `ContainerException` rather than half-understood. There is no v0 branch to delete — it refuses instead of adapting. |
| 28 | `DependencyCacheManager` — identical `hasInjectedProps` / `hasInjectedMethods` blocks in `instantiateWith()` and `construct()` | duplication | **keep** | Two entry points, not a leftover. Extracting them adds a method call to the construction hot path, which this repository measures (see `#181`, `#178`). Not a legacy item; noted so the next reader does not re-derive it. |

---

## Flagged, not changed — maintainer's call

**`psalm.xml` loads `Psalm\PhpUnitPlugin\Plugin`, but `projectFiles` is `src/` only.**
The plugin exists to type test code. Verified inert: analysing with and without it
produces identical output and the identical 99.15 % inference figure. Two coherent
fixes, and both are a policy decision rather than a cleanup:

- add `<directory name="tests"/>` to `projectFiles` and let the plugin do its job, or
- drop the `<plugins>` block **and** `psalm/plugin-phpunit` from `require-dev`.

**CI's `dependencies: locked` matrix leg, and `hashFiles('**/composer.lock')`.**
`composer.lock` has been in `.gitignore` since the initial commit and has never been
committed — correct for a library. So `composer install` on the `locked` leg behaves
exactly like the `highest` leg's `composer update`, and every cache key hashes an
empty set. The `tests` job therefore runs six identical-by-construction combinations
where three would do. Not *legacy* — it was never live — so it is out of this
change's scope, but it is real duplicated work on every CI run.

---

## Ready-to-use checklist for 3.0

Everything BC-protected, in the order that keeps the tree compiling at each step.

- [ ] Delete `src/Container/FullContainerInterface.php`.
- [ ] `Container`: change `implements FullContainerInterface, ArrayAccess` to
      `implements ContainerInterface, ArrayAccess`.
- [ ] `Container`: delete the `@psalm-suppress DeprecatedInterface` line and the
      three-sentence class-docblock paragraph explaining why the deprecated
      interface is implemented ("Implements the deprecated FullContainerInterface
      on purpose: …").
- [ ] Delete `tests/Unit/FullContainerInterfaceTest.php`, or rewrite it against
      `ContainerInterface` — its `test_every_method_is_declared` style checks are
      worth keeping under the new name.
- [ ] `docs/backward-compatibility.md`: drop the `FullContainerInterface` row from
      the covered table and the bullet below it.
- [ ] `docs/api-reference.md`: drop the `FullContainerInterface` row (line ~16) and
      the paragraph at ~58-59.
- [ ] `docs/performance.md` (~line 643): remove `FullContainerInterface` from the
      feature list.
- [ ] `UPGRADE.md`: move the 1.5→2.0 `FullContainerInterface` notes into the
      historical section and add a 2.x→3.0 entry saying the alias is gone.
- [ ] Delete `ContainerInterface::getStats()`.
- [ ] Delete `Container::getStats()` and its `#[Override]`.
- [ ] Delete the `@psalm-type StatsArray` declaration in `ContainerInterface` and
      the `@psalm-import-type StatsArray` line in `Container`.
- [ ] `ByteFormatter` becomes reachable only through
      `ContainerStats::processMemoryFormatted()` — keep it, that caller is `@api`.
- [ ] Delete `tests/Unit/ContainerStatsTest.php` — every test in it calls
      `getStats()`; `ContainerStatsObjectTest` already covers the same numbers
      through `stats()`.
- [ ] Delete the `getStats()`-vs-`stats()` agreement assertions in
      `tests/Unit/ContainerStatsObjectTest.php` (~63, ~77) and the single
      `getStats()['registered_services']` line in `tests/Unit/ScopeTest.php`
      (~680) — the `stats()->registeredServices` assertion sits directly above it.
- [ ] `docs/backward-compatibility.md`: drop the "**The array returned by
      `getStats()`**" carve-out — with the method gone there is nothing to carve out.
- [ ] `docs/api-reference.md` (~line 68 caveat, ~line 119 table row) and
      `docs/services.md` (~line 123): remove the `getStats()` mentions.
- [ ] `CHANGELOG.md`: one ⚠ entry naming both removals.
- [ ] Only if the floor also rises to 8.4 at 3.0 — a separate decision, not implied
      by the above: delete `DependencyResolver::$supportsLazyObjects`,
      `$lazyObjectsAvailable`, the `method_exists(ReflectionClass::class,
      'newLazyGhost')` probe and its `resetCache()` line, unguard the three
      `isLazy()` call sites, drop the `@infection-ignore-all` on
      `refusesForLaziness()`, and simplify `DependencyCacheManager::lazyProxyFor()`.
      **Do not do this while 8.3 is supported** — the 8.3 CI leg depends on it.
