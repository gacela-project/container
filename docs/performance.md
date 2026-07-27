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
| proven-instantiable classes | class shape — cleared |
| has-`#[Inject]`-properties | class shape — cleared |
| native lazy objects available | the PHP binary — recomputed, never differs |

The distinction is the point: a cache keyed on a class's *shape* needs clearing
when the classes change, and one keyed on the *runtime* never does.

Only positives are cached, so a class that was not loadable when it was first
asked about is never remembered as missing. Nothing here is a correctness
crutch — calling this costs only the reflection it throws away, and never
touches what a container was asked to keep: singletons, instances and bindings
belong to the container and go away with it.

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

## Related

- [Resolving services](resolution.md)
- [Managing services & introspection](services.md)
