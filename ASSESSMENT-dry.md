# DRY assessment — `src/Container/`

Survey of all 41 files under `src/Container/` (8,614 lines) looking for
duplication that is *accidental* rather than *paid for*.

The bar used throughout: a change earns its place only if it removes a rule
that is currently written twice, and costs nothing on a per-resolution path.
This library gates on benchmarks (`docs/performance.md`, #181, #163), and it
documents several duplications as deliberate — a hoisted `$hasParent`, an
inlined builder read, a repeated `is_string()`-before-`is_callable()` ordering.
Those are not defects, and "fixing" them would be a regression with a nicer
diff.

Line references are as of `044793b`.

---

## Changed

### 1. `ParamPlan` transcribed by hand into two files

- `src/Container/ContainerCompiler.php:204`
- `src/Container/ContainerValidator.php:136`
- `src/Container/ContainerValidator.php:218` (a partial `{name, declaringClass, ...}`)

`DependencyResolver:36` declares

```
@psalm-type ParamPlan = array{name: string, hasType: bool, type: string|null,
  isScalar: bool, inject: class-string|null, hasDefault: bool, default: mixed,
  declaringClass: string|null}
```

and the compiler and the validator each re-type the whole eight-key shape in a
`@param`. Both files *already* `@psalm-import-type CompiledPlans from
DependencyResolver`, so the mechanism was there and simply was not used for the
inner type. Adding a key to a plan meant editing three places, with only the
first one enforced.

Replaced by `@psalm-import-type ParamPlan from DependencyResolver`. Zero
runtime effect — this is documentation that both analysers check.

### 2. `DefinitionLoader::load()` carried two stacked docblocks

`src/Container/DefinitionLoader.php:59-69`

Two `/** ... */` blocks in a row. PHP associates only the second, so the first
was dead — and it documented a `$file` parameter the live one omits entirely.
Merged into one block that documents all three parameters.

### 3. `ContainerCompiler` walked the plans twice to answer one question

`src/Container/ContainerCompiler.php:73-89` (`render()`) and `:96-107`
(`compilable()`) each ran the same loop — `foreach (array_keys($this->plans))`,
`expressionFor($class, [])`, skip the nulls. The class docblock is explicit
that its verdict must be one verdict ("the report is the generator's own
verdict, not a second opinion"), and `Container::writeCompiledFactories()`
calls both, so every compiled build generated every expression twice.

Extracted `expressions(): array<class-string, string>`, memoized. The memo is
provably safe: `$plans`, `$bindings` and `$lazyClasses` are constructor-injected
and never written, and `skip()` is first-writer-wins, so the second pass could
only ever have re-derived the identical answer. Build-time only.

### 4. The tag merge rule existed in two implementations

- `src/Container/TagRegistry.php:38-55` (`tag()`)
- `src/Container/Container.php:1301-1324` (`taggedIds()`)

Both implement the same documented rule — *a string key replaces whatever sat
under it; an int key is appended unless the target already holds that id* — one
for registration, one for merging a scope's tags over its parent's. Two copies
of a merge rule is exactly the shape that drifts, and the two docblocks
already say the same thing in different words.

Extracted `TagRegistry::merge()`, used by both. `Container` loses its
`in_array` import along with the loop.

### 5. `#[Inject]` attribute reading, written twice

- `src/Container/DependencyResolver.php:1224-1230` (in `describeProperty()`)
- `src/Container/DependencyResolver.php:1403-1417` (`readInjectImplementation()`)

Same three steps — `getAttributes(Inject::class, IS_INSTANCEOF)`, bail on empty,
`newInstance()` — with the two copies disagreeing on how to test empty
(`$attributes === []` against `count($attributes) === 0`) and each carrying its
own `/** @var Inject */`. Extracted `injectAttributeOf()` returning `?Inject`,
which makes the cast a property of the helper rather than of every caller.

**Cold path**: both callers build *plans*, which are memoized per class per
process (`PlanRegistry::$plans`, `DependencyResolver::$propertyPlans`). Nothing
per-resolution runs through here.

### 6. Named-type description, written twice

- `src/Container/DependencyResolver.php:1232-1238` (`describeProperty()`)
- `src/Container/DependencyResolver.php:1377-1384` (`describeParameter()`)

Byte-identical six-line block deriving `[$typeName, $isScalar]` from a
`ReflectionNamedType`. Extracted `describeType()`.

Note the two plans still differ on `hasType` on purpose — a parameter reports
`$parameter->hasType()`, which is true for a union, where a property reports
`$type instanceof ReflectionNamedType`, which is not. That difference is
preserved: the helper only covers the part that was actually identical.

**Cold path**, same as #5.

### 7. Three CLI commands printed the same discovery line

- `src/Container/Console/Cli.php:120-121`, `:159-160`, `:184-185`

`compile`, `report` and `validate` each wrote
`sprintf("Discovered %d class(es).\n", count($classNames))` and then called
`warnIfNothingLoaded()` on the next line. Folded the announcement into that
method, now `reportDiscovery()`. Output is byte-identical and the ordering is
unchanged.

### 8. `Cli::run()` caught the same thing twice

`src/Container/Console/Cli.php:93-99`

`catch (CliException)` followed by `catch (Throwable)`, with identical bodies.
The first arm looked like it meant something and did not. Collapsed to one
arm; the comment now covers both cases, which is where the information
actually was.

### 9. The "Did you mean one of these?" block, written twice

- `src/Container/Exception/ContainerException.php:72-77`
- `src/Container/Exception/DependencyNotFoundException.php:30-36`

Two exception classes in the same namespace each rendered the suggestion list
by hand, identically apart from a trailing newline. This is user-visible
formatting duplicated across files, which is the drift-prone case — and
`FuzzyMatcher`, which produces the input, is the obvious home. Added
`FuzzyMatcher::renderSuggestions()`; the trailing newline stays at the call
site that wants it, so both messages are byte-identical to before.

---

## Deliberately left alone

### A. The injection tail in `DependencyCacheManager` — **hot path**

`src/Container/DependencyCacheManager.php:288-295` and `:476-483` are the same
eight lines:

```php
if (self::$hasInjectedProps[$class] ??= $resolver->hasInjectedProperties($class)) {
    $resolver->injectPropertiesOn($instance, $class);
}
if (self::$hasInjectedMethods[$class] ??= $resolver->hasInjectedMethods($class)) {
    $resolver->callInjectedMethodsOn($instance, $class);
}
```

This is the largest literal duplicate in the codebase, and it is the one that
must stay. `construct()` runs once per construction; a warm four-level resolve
is ~0.66μs (`docs/performance.md`), and the whole point of the surrounding code
— the inlined `argBuilders` read at `:449`, commented as being there *because a
method call per construction is measurable at ~1μs* — is that a call on this
path costs real time. Extracting `applyInjections()` would add exactly the call
that block was written to avoid.

### B. `injectPropertiesOn()` / `callInjectedMethodsOn()`

`src/Container/DependencyResolver.php:457-469` and `:493-505` share a
push-onto-`$resolvingStack` / `try` / `finally`-unset scaffold. Unifying it
needs a callback, which allocates a closure per call on a path that runs per
construction for any class that has either. Four lines of scaffolding is not
worth that.

### C. `argBuilderFor()` / `composeFor()` prologue

`src/Container/DependencyResolver.php:293-300` and `:524-529` both open with a
read of `$argBuilders[$className]` and a `false` short-circuit. `composeFor()`
documents at `:508-521` exactly why recursion must *not* enter through
`argBuilderFor()`: the first-construction deferral would cache a nested class's
parent as permanently ineligible. Sharing the prologue would put them back
together.

### D. `!is_string($x) && is_callable($x)`

`src/Container/BindingResolver.php:68`, `src/Container/DependencyResolver.php:791`
and `:818`. Three sites, each with a comment saying it is the same ordering as
the others and why ("never pays the function-table lookup", "nor risks that
lookup answering true for a class whose name collides with a function's"), and
`:813` names itself "the hottest of the three". Two of the three are per
constructor parameter of every node of every graph. Deliberate, documented, hot.

### E. The parent-chain shapes on `Container`

`bound()` (`:664-671`), `provides()` (`:289-294`), `ownsLocally()`
(`:1447-1452`), `isFactory()` (`:1130-1140`), `isFrozen()` (`:1143-1155`) and
`getRegisteredServices()` (`:1116-1127`) all look like
`local check || $this->parent?->same($id)`. They are six *different questions* —
`ownsLocally()`'s docblock at `:1441-1446` and `isFrozen()`'s at `:1147-1149`
exist precisely to say so, and `has()` at `:730-741` deliberately orders its
branches differently again. A shared "ask the chain" helper would hide the
distinctions the comments spend twenty lines establishing.

### F. `pushContextualBinding` / `pushCompiledFactories` / `pushLazyRegistrations`

`src/Container/Container.php:1378-1420`. Three recursive walks over
`liveScopes()`. What they share is one `foreach` line; the bodies differ
(a shadowing skip, a `+=` union, a delegated `adoptLazyFrom()`). Unifying them
behind a `Closure` parameter trades three legible methods for one indirect one.

### G. Twelve `dropArgBuilders()` calls in `Container`

`:500, 515, 533, 637, 681, 696, 771, 917, 926, 951, 1066, 1190`. Several are
redundant by nesting — `singleton()` calls it and then calls `bind()`, which
calls it again; `bindIf()` the same. That is a conservative "any registration
drops every builder" invariant, and thinning it is a behaviour question
(`bindIf()` on an already-bound id would stop dropping), not a DRY one. Left
for someone deciding it on purpose.

### H. `CallableKey::for()` / `signatureFor()`

`src/Container/CallableKey.php`. Parallel array/string/object dispatch, with a
docblock explaining at length that the granularity must differ — `for()` mixes
in `spl_object_id`, `signatureFor()` must not, because a reused id would hand a
new callable a dead one's parameter list. Merging them is the bug the comment
describes.

### I. Exception message heredocs

`ContainerException` (18 factories) and `CliException` (7) repeat a shape —
heredoc, blank line, remedy. `CliException:17-19` states the reason: one string
literal per message, deliberately, because "a `.` between two pieces of prose
is a mutation with no test that can meaningfully catch it". Any factoring here
reintroduces concatenation.

### J. `DefinitionLoader::applyTags()` throws the same message twice

`src/Container/DefinitionLoader.php:330-332` and `:336-338`. Real duplication of
a literal, but removing it means adding a five-line factory method to save one
repeated string inside a fifteen-line function where both copies are visible at
once. Net more code, no less risk of drift.

### K. `$resolutionChain` vs `$chainInfo`

`DependencyInvalidArgumentException` names the same parameter `$resolutionChain`
at `:18, :35, :52, :70, :91` and `$chainInfo` at `:112, :133` — and at `:112`
the local variable holding the formatted string is called `$chain`, the inverse
of every other factory. Cosmetic, but the class is `@api`, and
`docs/backward-compatibility.md` covers parameter names explicitly ("named
arguments are safe to use"). **Cannot be fixed before 3.0.** Recorded here so it
can go in the 3.0 list.

### L. The CLI command preludes

`src/Container/Console/Cli.php:107-109`, `:152-154`, `:177-179` — three
identical lines of `configFrom()` / `container()` / `sourceFrom()`. `compile()`
needs the `CliConfig` afterwards and the other two do not, so any extraction
returns a tuple with a discarded element (`[, $container, $source] = …`) or
leaves an unused variable in two of three callers. Three plain lines read
better than either. `configFrom()` cannot be called twice — it `require`s the
config file.

### M. Hoisted duplicate state

`DependencyResolver::$hasParent` (`:130-138`) duplicates `$parent !== null`;
`$supportsLazyObjects` (`:140-148`) duplicates `self::$lazyObjectsAvailable`;
`PlanRegistry::$plans` and `DependencyResolver::$argBuilders` are public
properties rather than accessors. Every one carries a comment saying it exists
to avoid indirection on the hot path. Untouched.

---

## Verification

All four gates run clean on the result, with the test count unchanged:

```
XDEBUG_MODE=off ./vendor/bin/phpunit                                      792 tests, OK
XDEBUG_MODE=off ./vendor/bin/phpstan analyze --no-progress --memory-limit=1G   no errors
XDEBUG_MODE=off ./vendor/bin/psalm --no-progress                          no errors
XDEBUG_MODE=off ./vendor/bin/php-cs-fixer fix                             no changes
```

No threshold in `infection.json5` or `phpunit.xml` was touched. No `@api`
signature was touched. Nothing on a per-resolution path was touched.

### Benchmarks

`sh tools/bench-compare.sh HEAD~1` — the paired, warm-up-discarding comparison
`docs/performance.md` insists on, run against the commit immediately before
this one rather than against `main`, so it measures *this* change and nothing
else.

**25 subjects, 50 assertions, 0 failures.** Timings scatter both ways within
noise (−7.1% to +4.3%, mean ≈ −1.7%), which is well inside the ±20% gate and
well inside what a laptop can resolve. The deterministic gate is the one worth
reading: `mem.peak` moves **+0.12%** on the subjects that build a plan and
**0.00%** on the ones that do not (`benchGetStoredInstance`, `benchCreateScope`,
`benchHasMiss`) — about 5kb, constant, and the ±5% gate is forty times that.

The split is the evidence for the claim above: only plan-building subjects moved
at all, which is exactly where the two `DependencyResolver` helpers live.
