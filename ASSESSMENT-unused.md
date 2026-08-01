# Unused-code assessment

Scope: `gacela-project/container` @ 2.0.1, branch `feat/137-dependency-graph`.

**Ground rule applied throughout.** This is a released library. "Not called from
inside this repo" is evidence of nothing for `@api` surface — that surface exists
for downstream consumers. Only `@internal` code was eligible for removal, and
only after a positive proof of non-reference.

## Method

| Technique | What it covered |
|---|---|
| `psalm --find-dead-code` over `src/` | unused classes, methods, properties, params, variables — the authority for "declared in `src`, never referenced from `src`" |
| `psalm --find-dead-code` over `src/ + tests/ + benchmarks/ + bin/` (temporary widened config) | separates "dead" from "only the tests use it" |
| `psalm --find-unused-psalm-suppress` | dead suppression annotations |
| `phpstan analyze` level max | unreachable branches, always-true/false conditions |
| Custom AST-ish scanners (`private`/`protected` methods, all properties incl. promoted, class constants, enum cases, whole classes, duplicate method bodies) cross-referenced against `src/ tests/ benchmarks/ bin/ tools/ docs/ *.md` | anything the analysers skip because a class is `@api` |
| `grep` over the consumer `gacela-project/gacela` (`src/ tests/ docs/ bin/ symfony-bridge/ tools/ custom/`, 7509 files) | proves a symbol is not part of the real downstream contract |
| `XDEBUG_MODE=coverage phpunit --coverage-clover` (48 never-executed lines) | pointers to possible unreachable branches |

`knip` was not used — it is a JavaScript tool and has nothing to say about PHP.

## Candidate table

### Removed

| Candidate | Verdict | Evidence |
|---|---|---|
| `AliasRegistry::has()` — `src/Container/AliasRegistry.php:70` | **REMOVE** | Class is `@internal` (also listed as `@internal` in `docs/backward-compatibility.md`). `AliasRegistry` is constructed in exactly one place, `Container.php:137`, and every call through `$this->aliasRegistry->` in `src/` is `inheritFrom` / `resolve` / `add` — `has` appears in none of them. `psalm --find-dead-code` on `src/` reports `PossiblyUnusedMethod`; the widened run stops reporting it, i.e. the only caller anywhere is `tests/Unit/AliasRegistryTest.php`. Zero hits in `gacela-project/gacela`. No dynamic dispatch in the codebase can reach it (the only variable method call is `DependencyResolver.php:1215`, `$instance->{$method['name']}(...)`, on user objects). Its two dedicated tests were removed with it. Secondary argument: it was also subtly wrong — unlike `resolve()` it never consults `$this->parent`, so it would have answered `false` for an alias inherited by a scope. |
| `DependencyResolver::exportPlans()` — `src/Container/DependencyResolver.php:428` | **REMOVE** | Class is `@internal`. Byte-identical duplicate of `DependencyCacheManager::exportCompiledPlans()` (`DependencyCacheManager.php:188`, `return $this->planRegistry->plans;`), which is the accessor production actually uses — `Container::getCompiledPlans()` (`Container.php:320`) and `Container::writeCompiledCache()` (`Container.php:458`) both go through it. `psalm --find-dead-code` reports `PossiblyUnusedMethod` on `src/`; widened run confirms the sole caller was one assertion in `tests/Unit/ClassDependencyResolverTest.php:116`. Zero hits in `gacela-project/gacela` (which references `DependencyResolver` only for `@psalm-import-type CompiledPlans`, an annotation import, not the method). The test kept its behavioural claim by injecting a `PlanRegistry` and reading its public `$plans` — no assertion was dropped. The `@psalm-type CompiledPlans` declaration stays; six files in `src/` plus the consumer import it. |

### Rejected — looks unused, is not

| Candidate | Verdict | Evidence |
|---|---|---|
| `FullContainerInterface` (whole interface, empty) | **KEEP** | Deliberate deprecated empty alias of `ContainerInterface`, `@api`, documented in `docs/backward-compatibility.md:18,40` as kept until 3.0 so 1.5 type-hints keep compiling. Removing it is the breaking change it exists to avoid. |
| Every `@api` class and its public methods (`Container`, `ContainerInterface`, `ContextualBindingBuilder`, `CompilationReport`, `ValidationReport`/`ValidationIssue`/`ValidationProblem`, `DependencyNode`, `ClassSource`, `PlanCache`, the four attributes, the four exceptions) | **KEEP, OUT OF SCOPE** | BC-protected. `CompilationReport::wasCompiled()/reasonFor()/explain()` in particular are called only from `tests/` inside this repo, which is exactly what a `compileReport()` return type is supposed to look like from the library's own side. |
| `Cli::main()` — `src/Container/Console/Cli.php:69` | **KEEP** | `psalm --find-dead-code` flags it because `bin/gacela-container` has no `.php` extension and is outside its project files. It is the CLI entry point: `bin/gacela-container:31`, `exit(Cli::main($argv));`. |
| `BindingResolver::$containerRef` — `BindingResolver.php:33` | **KEEP — Psalm false positive** | Psalm reports `UnusedProperty`. It is written at `BindingResolver.php:45` (`useSelfReference()`), read at `BindingResolver.php:78` (`$binding($this->containerRef?->get())`), and the constructor is called with the argument at `Container.php:147`. Three independent references; the finding is wrong. |
| `$ownerContainer` in `DependencyResolver::newLazyGhost()` — `DependencyResolver.php:907` | **KEEP** | Psalm reports `UnusedVariable`; the code says why in a 4-line comment at `DependencyResolver.php:885-888` and again at `899-901`: the closure captures it strongly *precisely because* it is never read — the capture is what keeps the container alive for an untouched ghost that outlives every other reference. Already suppressed for both analysers (`@phpstan-ignore ... closure.unusedUse`). Deleting it is a lifetime bug, not a cleanup. |
| 7 × `UnnecessaryVarAnnotation` (`DefinitionLoader.php:300`; `DependencyResolver.php:1146,1229,1240,1410,1413`; `DependencyTreeAnalyzer.php:118`) | **KEEP — measured** | Not "unused code" (docblocks), and load-bearing for the *other* analyser. Tested empirically: stripped all seven, PHPStan max then failed with `argument.type` at `DependencyTreeAnalyzer.php:121` — `BindingResolver::resolveType()` expects `class-string`, got `string`. Reverted. The remaining six survived PHPStan locally, but Psalm here runs on PHP 8.4 while CI runs static analysis on PHP 8.3; removing them buys nothing and risks a red CI on a runtime I cannot reproduce locally. |
| 13 × `UnusedPsalmSuppress` (incl. `@psalm-suppress UndefinedMethod` on `newLazyGhost`/`newLazyProxy`, `DependencyResolver.php:903,948`) | **KEEP — environment-dependent** | Surfaced only by `--find-unused-psalm-suppress`, which the repo's `psalm.xml` does not enable and CI does not run. The `newLazyGhost`/`newLazyProxy` suppressions guard PHP 8.4-only reflection APIs; local Psalm runs under PHP 8.4 and CI's static-analysis job pins 8.3, so "unused here" does not generalise. Not verifiable locally, no benefit, so untouched. |
| `FuzzyMatcher::calculateSimilarity()` early `return 1.0` — `FuzzyMatcher.php:69` | **KEEP** | Uncovered by tests, but it is the divide-by-zero guard for `$maxLength === 0`. Uncovered ≠ unreachable; removing it turns an empty-string pair into a `DivisionByZeroError`. |
| `DependencyResolver::isLazy()` early `return false` — `DependencyResolver.php:397` | **KEEP** | Uncovered locally only because `$supportsLazyObjects` is always true on the local PHP 8.4. It is the live PHP 8.3 path, and CI runs 8.3. |
| `composeFor()` cache short-circuits — `DependencyResolver.php:526,531` | **KEEP** | Uncovered but reachable: the docblock (`DependencyResolver.php:507-516`) states recursion enters `composeFor()` directly, bypassing `argBuilderFor()`'s own cache check. Already marked `@infection-ignore-all`. |
| `ContextualBindingBuilder.php:82` `throw new RuntimeException('Must call needs() before give()')` | **KEEP** | Uncovered defensive guard, and on an `@api` class — out of scope on both counts. |
| Remaining 40 uncovered lines (`CacheStamp:58`, `ClassSource:159,243,259,283`, `CompiledCacheWriter:66,67,137`, `ContainerCompiler:162,276,277`, `ContainerValidator:118-125,178`, `DefinitionLoader:143,154,175`, `DependencyCacheManager:289,294`, `DependencyResolver:371,374,527,531,753,973,1138`, `Cli:95,98,284,293,312`, `CliConfig:55,57,61,65,72,73,113`, `ContainerException:131`) | **KEEP** | All are reachable I/O-failure, filesystem-permission or malformed-input paths. Untested, not unreachable. |
| All private/protected methods in `src/` (112 scanned) | **NONE DEAD** | Every one has at least one in-file caller; Psalm reports no `UnusedMethod`. |
| All properties in `src/` (incl. promoted) | **NONE DEAD** | Custom scan found no property without a reader or writer; Psalm's only report is the `$containerRef` false positive above. |
| All class constants (`SCOPE_SWEEP_MIN`, `MAX_SUGGESTIONS`, `SIMILARITY_THRESHOLD`, `UNITS`, `BINDING_KEYS`, `ALLOWED_KEYS`, `FORMAT`, `EXIT_OK`, `EXIT_FAILURE`, `DEFAULT_CONFIG`) | **ALL USED** | Each has ≥1 reference outside its declaration. |
| All enum cases (11 × `CompilationSkipReason`, 4 × `ValidationProblem`) | **ALL USED** | Each is constructed at least once in `src/`. |
| Whole classes (41 in `src/`) | **NONE DEAD** | Every class has ≥1 reference from another `src/` file, except `Cli` (entry point, see above). |
| Unused imports | **NONE** | `.php-cs-fixer.dist.php:70` enables `no_unused_imports`; `php-cs-fixer fix` changes 0 of 195 files. |
| Unreachable branches / dead conditions | **NONE** | PHPStan level max over `src/`: no errors. Psalm errorLevel 1: no errors. |

## Result

Two symbols removed, 32 lines deleted, both `@internal`, both proven to have no
caller outside a test that existed only to exercise them. Nothing in the `@api`
surface was touched.

## Verification

| Check | Result |
|---|---|
| `phpunit` | OK — 790 tests, 1485 assertions (was 792/1487; the 2 removed tests were `AliasRegistryTest::test_has_*`, dedicated tests of the deleted method) |
| `phpstan analyze --memory-limit=1G` (level max) | No errors |
| `psalm --no-progress` | No errors found |
| `php-cs-fixer fix` | Fixed 0 of 195 files |
| `composer test-coverage-check` | Line coverage 97.45% (1836/1884), threshold 95.00% — OK (unchanged from the 97.45% baseline of 1838/1886) |
