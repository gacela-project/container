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

### Runtime parameters

Pass constructor arguments by **parameter name** as a second argument. Supplied
values override autowiring and defaults for the matching parameters (top level
only), and the instance is always built fresh:

```php
$report = $container->make(Report::class, ['month' => 3, 'format' => 'pdf']);

// Overrides an autowired dependency with an explicit instance
$service = $container->make(UserService::class, ['logger' => $myLogger]);
```

This resolves parameters that could not be autowired otherwise (e.g. a scalar
without a default). Overrides are per-call and never cached.

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

Runtime parameters work here too — override callable arguments by name:

```php
$container->resolve($handler, ['request' => $request]);
```

## `has()` vs. `bound()`

Two similar-looking questions with different answers:

- **`has($id)`** — will `get($id)` resolve? This is the PSR-11 contract. Because
  the container autowires, it is `true` for any instantiable class, whether or
  not you registered it.
- **`bound($id)`** — was this id *explicitly* registered, as a binding or a
  stored instance?

```php
$container = new Container();

$container->has(Greeter::class);    // true  — autowirable
$container->bound(Greeter::class);  // false — never registered

$container->has(LoggerInterface::class);   // false — no binding, cannot autowire an interface
$container->has(AbstractThing::class);     // false — not instantiable
$container->has('nonsense');               // false — not a class
```

Use `has()` before `get()`. Use `bound()` when you care about registration
itself — that is what `bindIf()` and `singletonIf()` check.

## Array access

The container implements `ArrayAccess`, so array syntax maps to the core methods:

```php
$logger = $container[LoggerInterface::class];   // get()
isset($container[LoggerInterface::class]);       // has()
$container['db'] = new PDO(/* ... */);           // set()
unset($container['temp']);                        // remove()
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
