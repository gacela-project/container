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
never affected.

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
$container->useCompiledFactories(require __DIR__ . '/cache/factories.php');
```

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

**Regenerate the file whenever a compiled constructor changes.** A stale file
will happily build the old shape.

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

## Related

- [Resolving services](resolution.md)
- [Managing services & introspection](services.md)
