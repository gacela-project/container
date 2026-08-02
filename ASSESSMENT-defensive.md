# Defensive-programming audit

Every `try`, `catch`, `finally`, `@` suppression and null-coalescing fallback in
`src/`, with a verdict and the evidence behind it.

The brief was "remove what hides errors or serves no purpose". The result is
lopsided on purpose: **32 constructs examined, 3 removed.** Most of what looks
defensive here is load-bearing, and the code already says so in a comment on the
branch itself. Where it does not, the reason is recorded below so the next
audit does not re-derive it.

The bar used for removal:

* the branch is **provably unreachable**, or
* the branch is **indistinguishable** from another branch that already covers it,

*and* removing it costs nothing to the four verification tools. A fallback that
merely looks nervous but produces a defined, documented result was kept.

---

## 1. `try` / `catch` / `finally`

| # | Location | Construct | Verdict | Evidence |
|---|---|---|---|---|
| 1 | `DependencyResolver::resolveDependencies()` | `try/finally` → `array_pop($this->buildStack)` | **justified** | The build stack is what `getContextualBinding()` matches against. Without the `finally`, a constructor that throws leaves its class on the stack and the *next* resolution applies contextual bindings meant for a class it never touched. |
| 2 | `DependencyResolver::injectPropertiesOn()` | `try/finally` → `unset($this->resolvingStack[…])` | **justified** | Comment states it. The stack feeds `getResolutionChain()` and `checkCircularDependency()`; a leaked entry reports a chain containing a class that was never resolved, and can fake a cycle. |
| 3 | `DependencyResolver::callInjectedMethodsOn()` | same | **justified** | Same invariant, for setter injection. |
| 4 | `DependencyResolver::composeFor()` | `try/finally` → `unset($this->buildingBuilders[…])` | **justified** | Recursion guard for builder composition. Without unwinding on throw the class stays permanently "under construction" and every later builder for it silently returns null — a permanent lost optimisation caused by one transient failure. |
| 5 | `DependencyResolver::instantiateFromPlan()` | `try/finally` → `unset($this->resolvingStack[…])` | **justified** | The cycle detector itself. Note the deliberate placement: property and method injection run *inside* the `try`, so a cycle reached through a property is caught like any other. |
| 6 | `DependencyResolver::callInjectedMethods()` | `try/finally` → `array_pop($this->buildStack)` | **justified** | As #1, so a setter's arguments see the same contextual bindings a constructor's would. |
| 7 | `Container::fireAfterResolving()` | `catch (Throwable) { $this->remove($id); throw $exception; }` | **justified** | **Rethrows** — nothing is hidden. Documented on `afterResolving()` ("A callback that throws removes the instance from the container … The exception propagates either way") and covered by tests. Dropping the instance is the point: a service whose post-construction wiring failed must not be served to the next caller as though it had succeeded. |
| 8 | `DependencyResolver::planDeep()` | `catch (Throwable) { return; }` | **justified** | Genuinely reachable: over a whole classmap, `describeClass()` hits `getDefaultValue()`, an `#[Inject]` attribute whose class will not load, or a class whose parent is missing. The compiler's contract is "describe what you can" — aborting the build on one bad class is the wrong answer for a tool handed everything discovery found. Not silent in the end: the class is absent from `compileReport()->compiled()`, and `report --strict` exits non-zero on it. |
| 9 | `CacheStamp::of()` | `catch (Throwable) { return null; }` | **justified** | Reachable: a plans file can name classes this build no longer contains, and `new ReflectionClass()` throws for them. `null` is a *defined value* in this API ("nothing to compare"), documented in the class docblock, not an error discarded. |
| 10 | `CompiledCacheWriter::write()` | `catch (Throwable) { continue; }` | **justified, narrow** | Verified on PHP 8.4 that `var_export()` does not throw for closures or resources, so this only fires under a userland error handler that converts the circular-reference warning into an exception. Kept because the documented fail-safe still holds: a skipped entry falls back to reflection and yields an identical object, so the cost is a missed optimisation, never a wrong result. |
| 11 | `DefinitionLoader::readJsonFile()` | `catch (JsonException)` → `ContainerException` | **justified** | Error *translation*, not suppression. `$exception->getMessage()` is carried into the container exception, so the parse error still reaches the user. |
| 12 | `DefinitionLoader::readYamlFile()` | `catch (Throwable)` → `ContainerException` | **justified** | Same, and it keeps an exception type from an optional (`suggest`-only) dependency from leaking out of the container's own API. Message preserved. |
| 13 | `Cli::run()` | `catch (CliException)` **and** `catch (Throwable)`, identical bodies | **REMOVED** | `CliException extends RuntimeException`, so `catch (Throwable)` already caught it — and did the byte-identical thing. Both branches were added in one commit (`30c589c`); they have never differed. No test can distinguish them. |

## 2. `@` error suppression

| # | Location | Construct | Verdict | Evidence |
|---|---|---|---|---|
| 14 | `CacheStamp::of()` / `::isCurrent()` | `@stat($file)` | **justified** | Result checked (`=== false`). The `@` suppresses a warning for exactly the case the function exists to answer — a file that is gone or unreadable. |
| 15 | `CompiledCacheWriter::put()` | `@file_put_contents(...) === false` | **justified** | Return checked and turned into `ContainerException::compiledCacheNotWritable()`. Preceded by an explicit `isWritable()` whose comment explains why the check is up front rather than leaning on the suppression: an application with its own error handler would otherwise get an `ErrorException` from inside the container. |
| 16 | `ClassSource::declarationsIn()` | `@file_get_contents` → `continue` on false | **justified, borderline** | Checked. An unreadable file is skipped during *discovery*, which is best-effort by design — and the CLI turns an empty outcome into the "none of the N discovered class(es) could be loaded" warning rather than a cheerful zero. |
| 17 | `Cli::ensureDirectory()` | `@mkdir(…, true)`, return ignored | **justified** | The only `@` here whose result is not checked, and it is still not a swallow: `CompiledCacheWriter::put()` runs immediately after and throws `compiledCacheNotWritable()` naming the path. Ignoring the return is also what makes a concurrent create benign. |

## 3. Fallbacks and guards

| # | Location | Construct | Verdict | Evidence |
|---|---|---|---|---|
| 18 | `ContainerCompiler::argumentFor()` | `$this->explanations[$type] ?? 'reason unknown'` | **REMOVED** | Provably unreachable. `skip()` writes `$reasons` and `$explanations` in lockstep, and *every* path on which `expressionFor()` returns null goes through `skip()` for that same class first (cycle, ineligible, no plan, not instantiable, injected props, injected methods, or a nested `argumentFor()` refusal). Proved empirically: replacing the fallback with a `throw` left 792/792 green, while forcing the branch failed 5 tests — so the line is on a live, covered path and the missing key never occurs. |
| 19 | `Cli::printReport()` | `$explanations[$class] ?? ''` | **REMOVED** | Same construction, and it contradicted the docblock four lines above it: *"Driven off `reasons()` rather than `explanations()`: the two are written together and keyed alike, and this way … there is no unreachable 'unknown' branch to defend."* There was one. Same two-way empirical proof as #18. |
| 20 | `ContextualBindingBuilder::give()` | `if (!isset($map[$class])) { $map[$class] = []; }` | **kept — was removed, then reverted** | A runtime no-op: `$map[$class][$needs] = …` vivifies the inner array anyway. Removing it is behaviour-identical and all 792 tests passed — but PHPStan at level max then reports `property.onlyWritten` for `$contextualBindings`, because that `isset()` is the single place the by-reference map is *read*. Restoring it is cheaper than a suppression the repo forbids. Comment added recording this. |
| 21 | `ContainerCompiler::expressionFor()` | `($plan['props'] ?? []) !== []` (and `methods`) | **justified** | `StoredClassPlan` really does declare `props?` / `methods?`: a cache file written before property/method injection existed has neither key. |
| 22 | `DependencyResolver::describeClass()` | `$plan['props'] ?? $this->describeProperties(…)` | **justified** | Same backward-compatibility case, with a comment saying why filling the gap beats trusting the file: a stale cache would otherwise turn injection off silently, in production. |
| 23 | `DependencyResolver::resolveParameter()` | `$param['type'] ?? $param['name']`, `$param['declaringClass'] ?? ''` | **justified** | Both only shape an exception message, and `declaringClass` is genuinely nullable — a closure parameter has no declaring class. Nothing is hidden; the exception is thrown either way. |
| 24 | `Container::forClosures()` | `$this->selfReference->get() ?? $this` | **justified** | Documented on `withSelfReference()`: the reference is weak on purpose, and a closure still has to be handed something once a decorator is dropped. |
| 25 | `FuzzyMatcher::calculateSimilarity()` | `if ($maxLength === 0) return 1.0;` | **justified** | Guards a division by zero. Unreachable from today's single call site (a class-string target is never empty) — but removing it plants a latent `DivisionByZeroError` in a helper, which is strictly worse than an unused guard. |
| 26 | `ByteFormatter::format()` | `max(0, min($pow, count(UNITS) - 1))` | **justified** | The comment says it: the clamp is what makes the array offset provably valid to the analysers. The lower bound is unreachable at runtime and load-bearing statically. |
| 27 | `FuzzyMatcher::findSimilar()` | `if (count($candidates) === 0) return [];` | **kept** | Redundant — the `array_map`/`usort`/`array_filter`/`array_slice` pipeline returns `[]` for empty input anyway. Kept because it is a guard clause, not a fallback: it hides no error and changes no result. Not worth churn on an exception path. |
| 28 | `DependencyCacheManager::construct()` | `if (!$this->isInstantiable($class)) throw …` | **justified** | Named in the brief, and the comment on the branch confirms it: without the guard `get()` emitted a raw PHP `Error` from inside the container while `has()` said the id was unresolvable. API consistency, not defensiveness. |
| 29 | `DependencyResolver::isInstantiable()` | `class_exists($className) && …` | **justified** | Looks duplicated against the `class_exists()` in `DependencyCacheManager::isInstantiable()`, but each guards its own entry point, and `ClassDependencyResolverTest` calls the resolver's directly with `'GacelaTest\Fake\NeverDefined'`. Both are covered; neither is spare. |
| 30 | `BindingResolver::resolve()` | trailing `return null` | **justified** | Not a swallow. `Container::get()` returning null for an unresolvable id is the documented API — `getOrFail()` exists precisely to get the other behaviour — and it is BC-locked at 2.0. |
| 31 | `FactoryManager::getPendingExtensions()` | `?? []` | **kept** | The only caller guards with `hasPendingExtensions()` first, so the fallback is unreachable today. Kept: it is a public method on a collaborator, an empty list is the honest answer to "what is pending for an id with nothing pending", and no error is concealed. |
| 32 | `argBuilderFor()`, `eligibleForBuilders()`, `refusesForLaziness()` | `@infection-ignore-all` fail-safe branches | **justified** | Read the comments before touching these. Every branch is fail-safe by construction: mutating one produces a *missed optimisation*, not a wrong object, because falling back to the resolution path returns the identical result. The branches that *are* observable — the refusals in `composeBuilder()` — stay covered and fail ten tests if deleted. |

---

## What changed

Three removals, all in build-time / CLI code, none on the benchmarked hot path
(`DependencyResolver`, `DependencyCacheManager`, `Container::get()` are
untouched). No exception type thrown by any `@api` method changed, so
`docs/backward-compatibility.md` is unaffected.

1. `Cli::run()` — dropped the `catch (CliException)` arm that duplicated the
   `catch (Throwable)` arm byte for byte.
2. `ContainerCompiler::argumentFor()` — dropped `?? 'reason unknown'`. A build
   report that prints "reason unknown" is a report that lies about what the
   compiler knows; the invariant that makes the direct read safe is now a
   comment instead of a fallback.
3. `Cli::printReport()` — dropped `?? ''`, which would have printed a refusal
   with a blank explanation.

## What was deliberately kept

The six `try`/`finally` recursion and stack guards, `fireAfterResolving()`'s
catch-remove-**rethrow**, the two `catch → ContainerException` translations in
`DefinitionLoader`, both compile-time `catch (Throwable)` fall-backs
(`planDeep()`, `CacheStamp::of()`), all four `@` suppressions, and every
`@infection-ignore-all` branch. Each is either the only thing keeping an
invariant true across a throw, an error *translation* that preserves the
message, or a documented fail-safe whose worst case is a missed optimisation.

One near-miss worth recording: removing the `isset()` in
`ContextualBindingBuilder::give()` is a behaviour-preserving simplification that
passes all 792 tests and *fails* PHPStan, because that `isset()` is the only
read of a by-reference property. It was reverted, not suppressed.

## Observation outside this remit

`DefinitionLoader::load()` carries two stacked docblocks (lines 59–69); the
first is an orphan from a merge and its `@param $file` no longer matches the
signature it precedes. Left alone — it is documentation, not defensive code,
and does not belong in this commit.
