# Performance & Compilation

[← Back to index](../README.md#documentation)

## Warm up

Pre-resolve dependencies so the reflection work happens once, up front:

```php
// During application bootstrap
$container->warmUp([
    UserService::class,
    OrderService::class,
    PaymentProcessor::class,
]);

// Later calls reuse the warmed reflection caches
$service = $container->get(UserService::class);
```

`warmUp()` only lives for the current process — a new request warms up again.

## One plan cache for several containers

A container caches its constructor plans per instance, and a
[scope](scopes.md#compiled-plans-are-shared) shares its parent's. Sibling
containers — one per module, which is where a modular application ends up —
had no such axis, so every one of them re-planned whatever the modules had in
common. Hand them the same `PlanCache` and the first to touch a class plans it
for the rest:

```php
use Gacela\Container\PlanCache;

$plans = new PlanCache();

$users  = new Container($userBindings,  [], [], $plans);
$orders = new Container($orderBindings, [], [], $plans);
```

Ten containers each resolving the same four-level chain (PHP 8.4, no JIT,
`phpbench`, mode of 5×1000 revolutions):

| | mode | peak memory |
|---|---|---|
| A cache each | 55.315μs | 82.61mb |
| One shared cache | **37.067μs** | **45.79mb** |

**Reflection output is all that is shared.** A plan records what a constructor
asks for, never how a container satisfied it, so bindings, contextual bindings,
aliases, tags, singletons, stored instances, `lazy()` registrations and compiled
factories all stay private to the container they were registered on. Two
containers sharing a cache still resolve the same class differently if their
bindings differ — that is the whole point of the line.

Seed it from a compiled cache file to pay for reading it once instead of once
per container:

```php
$plans = new PlanCache(Container::loadCompiledCache(__DIR__ . '/cache/container.php'));
```

A plan already in the cache wins over a compiled one for the same class: the
first was built by reflection in this process, the second came off disk and may
be describing a constructor that has since changed.

`count()` and `classes()` say what the cache holds, which is how a build asserts
the sharing is happening at all.

## Process-global caches

Some reflection output is cached in `static` properties, shared by every
container in the process and untouched by dropping one. That is normally free —
a class definition cannot change while the process runs — and it is exactly
wrong when the set of loadable classes *does* change: code generation, a
`cache:warm` command, a worker that re-bootstraps between jobs, a test suite
that declares classes as it goes.

```php
Container::resetStaticCaches();
```

| Cache | Lifetime |
|---|---|
| property plans (the `#[Inject]` scan) | class shape — cleared |
| `#[Lazy]` on a class | class shape — cleared |
| `#[Singleton]`/`#[Factory]` on a class | class shape — cleared |
| proven-instantiable classes | class shape — cleared |
| has-`#[Inject]`-properties | class shape — cleared |
| declares `__invoke` | class shape — cleared |
| native lazy objects available | the PHP binary — recomputed, never differs |

The distinction is the point: a cache keyed on a class's *shape* needs clearing
when the classes change, and one keyed on the *runtime* never does.

Only positives are cached, so a class that was not loadable when it was first
asked about is never remembered as missing — `has('App\Generated\Handler')`
answering `false` before the file exists does not stop it answering `true`
after. Nothing here is a correctness crutch — calling this costs only the
reflection it throws away, and never touches what a container was asked to
keep: singletons, instances and bindings belong to the container and go away
with it.

`has()` on an unregistered class shares the proven-instantiable entry above
rather than reflecting for itself, so the probe leaves behind the constructor
plan the following `get()` needs. Two consequences worth knowing: `has()` warms
the cache it reads, and containers sharing a
[plan cache](#one-plan-cache-for-several-containers) share that answer too.

Call it from your framework's own reset, so that reset can be honest about what
it clears.

## Compiled container cache

To skip reflection **across requests**, compile the constructor plans once
(for example in a build/deploy step) and load them on boot:

```php
// Build step: write the compiled plans to an opcache-friendly PHP file
$container = new Container($bindings);
$container->writeCompiledCache([
    UserService::class,
    OrderService::class,
], __DIR__ . '/cache/container.php');

// Runtime: feed the plans back through the constructor — no reflection
$plans = Container::loadCompiledCache(__DIR__ . '/cache/container.php');
$container = new Container($bindings, [], $plans);

$service = $container->get(UserService::class);
```

Classes whose constructor default values cannot be statically exported are
skipped automatically and fall back to reflection at runtime, so correctness is
never affected. Neither does a cache that has gone out of date — see
[staleness](#staleness) below.

Use `compile()` when you want the plans array directly, without writing a file:

```php
$plans = $container->compile([UserService::class, OrderService::class]);
```

## How it works

- Per-parameter reflection is extracted into plain-data **constructor plans**.
- The resolver consumes those plans instead of reflecting each time.
- A compiled cache seeds the plans on construction, so warmed classes resolve
  with no `ReflectionClass` calls at runtime.

## What this actually buys you

The container memoizes reflection per instance. Once a class has been resolved
on a given container, later resolutions of that class are already cache hits —
so **a compiled cache does nothing for a long-lived, already-warm container.**

Where it pays off is a *cold* container: the typical PHP request, which builds a
container, resolves a handful of services, and exits.

Resolving a four-level dependency chain on a freshly constructed container
(PHP 8.4, no JIT, `phpbench`, mode of 5×1000 revolutions):

| | mode | peak memory |
|---|---|---|
| Cold container, reflection | 4.687μs | 9.56mb |
| Cold container, compiled plans | **2.716μs** | **5.54mb** |

Roughly **1.7× faster and 40% less memory** per request.

For comparison, the same chain on an already-warm container resolves in ~1.6μs
whether or not plans were supplied — the caches have already absorbed the cost.

So: reach for the compiled cache when you are optimising per-request bootstrap.
It is not a hot-loop optimisation, and it will not show up in a benchmark that
reuses one container.

## Generated constructor code

The compiled cache above still walks the resolver, just with the reflection
already done. `writeCompiledFactories()` goes further and emits plain `new`
expressions, taking the resolver off the path entirely:

```php
// build step
$container = new Container($bindings);
$compiled = $container->writeCompiledFactories(
    [UserService::class, OrderService::class],
    __DIR__ . '/cache/factories.php',
);

// runtime
$container = new Container($bindings);
$container->useCompiledFactories(
    Container::loadCompiledFactories(__DIR__ . '/cache/factories.php'),
);
```

Load the file with `loadCompiledFactories()` rather than `require`-ing it: the
same [staleness](#staleness) check that protects compiled plans protects
generated expressions, and a raw `require` skips it.

Resolving the same four-level chain on a fresh container:

| | mode | peak memory |
|---|---|---|
| Reflection | 4.530μs | 11.44mb |
| Compiled plans | 2.655μs | 7.41mb |
| **Generated code** | **0.806μs** | **5.51mb** |

About **5.6x faster than reflection** and 3x faster than plans.

### What it will not compile

The generator is deliberately conservative and simply leaves out anything it
cannot decide statically:

- classes behind a **binding** — the binding could change after compilation
- **scalar** or untyped parameters — the value may come from a contextual binding
- `#[Inject]`, `#[Singleton]`, `#[Factory]`, `#[Lazy]` — lifetime and
  construction belong to the runtime
- classes registered with `lazy()` — same reason, without the attribute to see
- abstract classes and interfaces, and anything in a dependency cycle

Everything omitted resolves normally, so the file is only ever an optimisation.
`writeCompiledFactories()` returns the list of classes it actually compiled, so
you can assert on it in your build.

### Registration still wins

Those rules describe the container the file was written *from*. They cannot
speak for the one it is installed into, so registration on the receiving
container outranks a generated expression:

```php
$container->useCompiledFactories(Container::loadCompiledFactories($file));

$container->bind(Mailer::class, LoggingMailer::class);
$container->get(Mailer::class);   // LoggingMailer, not the generated `new Mailer`
```

A `bind()`, a `singleton()` or a `set()` for the same class sends it back down
the normal path, whichever side of `useCompiledFactories()` the call happens on.
Order does not matter, and neither does whether the build container had the same
bindings — the generated file cannot quietly out-vote the container you
configured.

### Asking why

`compileReport()` answers the same question programmatically, and adds the part
the returned list cannot tell you — *why* a class dropped out:

```php
$report = $container->compileReport([UserService::class, OrderService::class]);

foreach ($report->explanations() as $class => $why) {
    echo "skipped {$class}: {$why}\n";
}

// skipped App\OrderService: parameter $dsn is scalar or untyped, so its value
// may come from a contextual binding
```

Nothing is written, and its `compiled()` set is exactly what
`writeCompiledFactories()` returns for the same input — the report is the
generator's own verdict, not a second opinion.

`reasonFor()` gives the machine-readable half, a `CompilationSkipReason` case
per branch that can refuse a class:

| Case | Why |
|---|---|
| `Bound` | the class is bound, and the binding could change after compilation |
| `LazyRegistration` | registered with [`lazy()`](bindings.md#deferred-registration) |
| `NotInstantiable` | an interface, an abstract class, or a non-public constructor |
| `LifetimeAttribute` | `#[Singleton]`, `#[Factory]` or `#[Lazy]` |
| `InjectedProperty` | a `new` expression cannot assign `#[Inject]` properties |
| `InjectedMethod` | a `new` expression cannot make the `#[Inject]` method calls |
| `InjectedParameter` | an `#[Inject]` parameter is resolved at runtime |
| `ScalarParameter` | a scalar or untyped constructor parameter |
| `DependencyCycle` | the class takes part in a cycle |
| `NoPlan` | the planner never described it |
| `Dependency` | one of its constructor dependencies could not be compiled |

Which makes "these classes must compile, fail the build otherwise" a few lines:

```php
$report = $container->compileReport($mustCompile);

if ($report->skipped() !== []) {
    foreach ($report->explanations() as $class => $why) {
        fwrite(STDERR, "{$class}: {$why}\n");
    }
    exit(1);
}
```

### Finding the classes to compile

Everything above takes a `list<class-string>`, and nothing produces that list.
Written by hand it costs maintenance proportional to the size of the
application, and it fails quietly: a class you add and forget to list keeps
resolving through reflection, no slower than before it existed and no faster
than it needs to be. Nothing reports it.

`ClassSource` is where the list comes from instead:

```php
use Gacela\Container\ClassSource;

// Composer's own map — authoritative, no parsing, and already present in the
// deployment where compiling is worth doing.
$container->writeCompiledFactories(
    ClassSource::fromComposerClassmap(),
    'var/container-factories.php',
    $commitSha,
);

// For a dev setup with no optimized autoloader.
$report = $container->compileReport(ClassSource::fromDirectory('src/'));
```

`fromComposerClassmap()` reads `vendor/composer/autoload_classmap.php`, which
Composer writes for `--optimize-autoloader`; pass a path to point at another
one. `fromDirectory()` tokenises the tree rather than loading it, so a file that
declares nothing is never included for whatever it does at the top level.
`fromList()` wraps a list you already have, so one parameter covers every case.

Interfaces, traits and enums are left out of a directory scan: none can ever be
compiled, and listing them would pad every report with refusals you can do
nothing about. Abstract classes are kept — `NotInstantiable` naming one is
useful.

#### Discovery describes; it never resolves

`warmUp()` does **not** accept a `ClassSource`, and the asymmetry is deliberate
rather than an oversight — the signatures are otherwise identical enough to
invite the assumption.

Warming resolves: it runs constructors, which is the point when you are warming
a handful of services you were about to build anyway. Over a discovered set it
is the wrong operation twice over. It would construct the whole application at
build time, opening every connection those constructors open, and it would throw
on the first class whose scalar nothing supplies — and a classmap is full of
those. So a `ClassSource` goes through a describe-only path: reflection only, no
constructor runs, and a class that cannot be planned is skipped rather than
fatal, leaving the compiler as the one place that decides what is compilable.

Passing a list is unchanged, warming included. The two paths differ only in
which classes end up planned: resolving follows bindings, so a bound interface
is planned through to its implementation, while describing follows declared
types.

### Proving it resolves, before it runs

An autowiring container tells you a dependency is missing when a request
reaches it. `validate()` answers the same question at build time, so a deploy
fails instead:

```php
$report = $container->validate([HomeController::class, ApiController::class]);

if (!$report->isValid()) {
    fwrite(STDERR, $report->render());
    exit(1);
}
```

```
14 class(es) checked, 1 problem(s):

[missing-class] App\Contract\Clock: it is an interface and nothing is bound to it
    via App\HomeController -> App\ReportBuilder
```

Or from the command line, where it exits non-zero on any problem:

```
vendor/bin/gacela-container validate
```

**Nothing is constructed.** Classes are described rather than resolved, and
whether an id can be satisfied is answered by `has()` on your container — the
same question resolution asks. So validating cannot open a connection, and
cannot drift from what resolution actually does by re-deriving any of it: a
binding, an alias, a stored instance or a parent scope all count here because
they count to `has()`.

It reports what is decidable ahead of time:

| Problem | Meaning |
|---|---|
| `MissingClass` | the class does not exist, or is an interface with nothing bound to it |
| `NotInstantiable` | abstract, or a non-public constructor, with nothing bound |
| `UnresolvableParameter` | untyped, or a scalar with no default and no contextual binding |
| `DependencyCycle` | the class takes part in a constructor cycle |

Each issue carries the `chain` that reached it, because "which of my entry
points does this break" is the question a build is actually asking. A cycle is
reported rather than thrown — the point is to list every problem, not stop at
the first.

It does not predict what a closure binding returns. Only running that can
settle it, and guessing would make this a second resolver, which is the thing
[the compiled file is careful not to be](#generated-constructor-code).

### Compiling from the command line

Everything above needs a bootstrap script that builds a container, points it at
some classes and calls the write methods with a stamp. That script is the same
in every application, so it ships as one:

```
vendor/bin/gacela-container compile \
    --plans=var/container-plans.php \
    --factories=var/container-factories.php \
    --stamp=$(git rev-parse HEAD)

vendor/bin/gacela-container report --strict
```

`compile` writes either file or both and says what it wrote — how many classes
were discovered, how many factories were generated, how many were refused.
`report` prints the same verdict `compileReport()` returns, one line per refusal
with its `CompilationSkipReason`, and `--strict` exits non-zero if anything was
refused, so a build can assert that a class it expects to be compiled actually
is.

#### It has to build *your* container

```php
<?php // gacela-container.php

use Gacela\Container\ClassSource;

return [
    'container' => static fn () => require __DIR__ . '/config/container.php',
    'source'    => ClassSource::fromDirectory(__DIR__ . '/src'),
    'plans'     => 'var/container-plans.php',
    'factories' => 'var/container-factories.php',
];
```

The `container` key is the part that cannot be inferred, and it is not a
convenience. The generator [refuses a bound class](#what-it-will-not-compile)
precisely because a binding can change after compilation — so a container built
without your `bind()` calls does not refuse them. It generates a `new` for a
class your application binds, writes it out, and that file is then installed into
a container that *does* bind it. Naming a callable that returns the configured
container is what stops that.

Everything in the file is a default the command line can override, so CI and a
developer's shell run the same thing without repeating the flags.

#### It does not guess

Two failure modes this replaces are silent, so the command is deliberately loud
about both:

- **An unknown option is an error.** `--factorys=…` does not quietly write no
  factories and exit 0; it names the option and fails.
- **Classes that could not be autoloaded are reported.** Discovery reads
  declarations off disk, planning needs them loaded, and a `report` that finds
  200 classes and can load none of them otherwise prints a cheerful zero having
  done nothing. It says so instead.

Omitting `--stamp` is legal and mentioned in the output, since the per-entry
mtime check that replaces it is the slower of the two — see
[staleness](#staleness).

No console framework: the argv parsing is thirty lines, and `psr/container`
stays the only runtime dependency. The CLI is `@internal` — a build tool, not
part of the API surface.

### Skipping work entirely

The compiled cache makes construction cheaper. `#[Lazy]` avoids it altogether
for services a request never reaches — usually the bigger win:

| | mode | peak memory |
|---|---|---|
| Untouched expensive branch, eager | 6.317μs | 12.30mb |
| Untouched expensive branch, `#[Lazy]` | **1.513μs** | **6.45mb** |

Roughly **4x faster and half the memory**, because neither the service nor its
subtree is ever built. The same applies to a class you cannot annotate: register
it with [`lazy()`](bindings.md#deferred-registration). See
[attributes](attributes.md#lazy).

Reproduce any of this with `composer bench`.

## Staleness

Regenerate the cache whenever a compiled constructor changes. When that does not
happen — a deploy that ships new code over an old cache — the container does not
build the old shape:

Every entry is written with a **fingerprint** of the file its class was declared
in (path, mtime, size). `loadCompiledCache()` and `loadCompiledFactories()`
compare it, and an entry whose file has changed is dropped. A dropped entry
behaves exactly like one that was never written: the class falls back to
reflection, nothing throws, and correctness never depends on the cache being
current. The same holds for a class whose file has been deleted, and for classes
with no file of their own — an internal class cannot go stale by an edit, so its
entry is kept.

The file rather than the constructor signature, because verifying a signature
means reflecting the class, which is the work the cache exists to avoid.

### Trading the per-entry check for a build stamp

The check costs one `stat` per entry. For a map of a few thousand classes that
can cost more than the reflection it saves, so pass a **build stamp** instead —
a deploy id, a commit sha, anything that changes when the code does:

```php
// build step
$container->writeCompiledCache([UserService::class], $file, getenv('DEPLOY_SHA'));

// runtime
$plans = Container::loadCompiledCache($file, getenv('DEPLOY_SHA'));
```

| At write | At load | Result |
|---|---|---|
| no stamp | no stamp | every entry checked individually |
| stamp | same stamp | file taken whole — no `stat` at all |
| stamp | different stamp | file discarded whole, everything reflects |
| stamp | no stamp | every entry checked individually |
| no stamp | stamp | every entry checked individually |

So the stamp is a promise: *this file belongs to this build*. It is the cheaper
trade when the deploy id is trustworthy, and one comparison per process instead
of one per class. Both `writeCompiledFactories()` and `loadCompiledFactories()`
take it the same way.

A file written by a different version of this package is refused with a
`ContainerException` rather than half-read — it is a build artifact tied to the
version that produced it.

## Releasing a container

Dropping the last reference to a container frees it and everything it owns
immediately, by reference counting. Nothing waits for the cycle collector, so a
long-running worker that creates a container or a [scope](scopes.md) per request
does not accumulate them, and may run with `gc_disable()`.

This was not always true: three collaborators used to hold a strong reference back
to the container, making every container a cycle of nine objects. Cold containers
built and dropped in a loop retained about 1.6kb each until the collector ran —
1.51mb per thousand — and an explicit `gc_collect_cycles()` did not return all of
it, leaving around 120kb per thousand behind. The same loop retains nothing now, at
any count (PHP 8.5, `gc_disable()`, `memory_get_usage()` against a warmed baseline).

None of the benchmarks on this page can see that difference — `phpbench` calls
`gc_disable()` in its executor template, so a cycle simply accumulates for the
length of the run. It is covered by tests asserting on `WeakReference::get()`
instead, which is the only thing that distinguishes "released" from "unreachable
but still allocated".

The one deliberate hold is an **uninitialized lazy instance**: first touch is what
resolves its dependencies, so it keeps its container alive until then. Touching it
ends the hold.

## Reproducing the figures above

```bash
composer bench
```

Every figure on this page is a mode of iterations, never a mean, and the suite
re-runs any iteration deviating 5% or more from the mean of its set.

**Discard the first run of a session.** With a cold opcache and page cache it
measures several times noisier than the second — up to ±40% relative standard
deviation against ±8% on the same subject moments later — which is enough to
swamp the differences this page reports. Run it twice and read the second.

### Comparing a change against main

```bash
composer bench:compare              # against main
composer bench:compare -- origin/main
BENCH_FILTER=benchResolve composer bench:compare
```

Measures the merge base, then the working tree, and prints both columns — with
the warm-up discard already in place, which is the step that matters. Comparing
a cold run against a warm one reports a regression that is not there and hides
one that is; every performance change here has hand-rolled this comparison, and
the ones that skipped the warm-up read as much as 7% out.

Uncommitted work is measured, not lost: the script stashes it, checks the ref
out, and puts it back.

### What is gated, and at what

The benchmark job is **required**. It was advisory pending a measurement of the
false-positive rate, which #132 supplied, and in the meantime a 12% regression
landed with the job green.

Every subject carries two assertions, declared on the class so that a new
benchmark is gated by existing — a contributor has to argue their way *out* of a
gate rather than into one:

| Gate | Threshold | What it is for |
|---|---|---|
| `time.avg` | ±20% | The cliff. Wide on purpose: it catches a collapse like the +17–41% in #45, not drift. |
| `mem.peak` | ±5% | Deterministic — no scheduler, no cache state — so it is held far tighter than any timing can be. |

Five subjects carry a tighter time budget of **±7%**, roughly twice the measured
noise floor, because they are the ones this page quotes figures for:

- `benchResolveDeepChain`
- `benchColdResolveDeepChain`
- `benchColdResolveDeepChainGenerated`
- `benchColdResolveDeepChainCompiled`
- `benchColdResolveAcrossSiblingsSharingPlans`

The cliff gate catches #45; the budget gate catches the kind of 12% regression
that got through in #150. A figure on this page can be traced to the assertion
defending it.

The baseline comes from the merge base's `src` and `benchmarks`, not from a
stored file — so it is always taken *after* whatever already landed, and a
regression that was reviewed and accepted does not re-fire on the next PR.

The comparison table is posted on the pull request itself, pass or fail. A
regression is exactly when the numbers are worth reading, and a job log is not
where anyone reads them.

## Related

- [Resolving services](resolution.md)
- [Managing services & introspection](services.md)
