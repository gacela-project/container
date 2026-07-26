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

### Skipping work entirely

The compiled cache makes construction cheaper. `#[Lazy]` avoids it altogether
for services a request never reaches — usually the bigger win:

| | mode | peak memory |
|---|---|---|
| Untouched expensive branch, eager | 6.317μs | 12.30mb |
| Untouched expensive branch, `#[Lazy]` | **1.513μs** | **6.45mb** |

Roughly **4x faster and half the memory**, because neither the service nor its
subtree is ever built. See [attributes](attributes.md#lazy).

Reproduce any of this with `composer bench`.

## Related

- [Resolving services](resolution.md)
- [Managing services & introspection](services.md)
