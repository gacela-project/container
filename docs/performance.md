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

## Related

- [Resolving services](resolution.md)
- [Managing services & introspection](services.md)
