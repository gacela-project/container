# Resolving Services

[← Back to index](../README.md#documentation)

## get()

Retrieve or create a service. Returns `mixed`.

```php
$service = $container->get(UserService::class);
```

## make()

Resolve a class to a **typed, non-null** instance. Prefer this over `get()`
when you want the return type preserved:

```php
$service = $container->make(UserService::class); // returns UserService, not mixed
```

If the class cannot be resolved, `make()` throws `DependencyNotFoundException`.

## getOrFail()

Like `get()`, but throws `DependencyNotFoundException` instead of returning
`null` when the id cannot be resolved:

```php
$service = $container->getOrFail(UserService::class);
```

## resolve() — inject into any callable

Automatically inject dependencies into any callable based on its parameter
type hints:

```php
$result = $container->resolve(function (LoggerInterface $logger, CacheInterface $cache) {
    $logger->info('Cache cleared');
    return $cache->clear();
});
```

## Transient vs. shared instances

By default, autowired services are **transient**: each `get()` builds a fresh
instance graph, so two resolutions do not share child instances.

To share a single instance, register it with
[`singleton()`](bindings.md#fluent-registration) or the
[`#[Singleton]`](attributes.md) attribute.

## Related

- [Bindings & registration](bindings.md)
- [Performance & compilation](performance.md)
- [Error handling](error-handling.md)
