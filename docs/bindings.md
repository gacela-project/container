# Bindings & Registration

[← Back to index](../README.md#documentation)

Bindings map an abstract type (usually an interface) to a concrete
implementation, a closure, or an existing object.

## Constructor bindings

```php
$bindings = [
    LoggerInterface::class => FileLogger::class,          // class-string
    CacheInterface::class => new RedisCache('localhost'), // object
    ConfigInterface::class => fn() => loadConfig(),        // closure
];

$container = new Container($bindings);
$logger = $container->get(LoggerInterface::class); // Returns FileLogger
```

## Fluent registration

Register bindings after the container is constructed:

```php
$container = new Container();

// Map an abstract to a concrete (class-string, closure, or object)
$container->bind(LoggerInterface::class, FileLogger::class);

// Register a shared instance (created once, reused on every resolution)
$container->singleton(CacheInterface::class, RedisCache::class);
$container->singleton(Clock::class); // the class itself, as a singleton
$container->singleton(ConfigInterface::class, fn() => loadConfig()); // memoized closure

$container->get(CacheInterface::class); // same instance every time
```

`singleton()` accepts the same concrete forms as `bind()`:

- a **class-string** — the resolved instance is created once and reused
- an **object** — stored as-is (already a single shared instance)
- a **closure** — memoized, so it runs only on the first resolution
- **omitted** — the `$abstract` itself is treated as the concrete class

## Contextual bindings

Provide different implementations depending on which class needs them:

```php
// UserController gets FileLogger, AdminController gets DatabaseLogger
$container->when(UserController::class)
    ->needs(LoggerInterface::class)
    ->give(FileLogger::class);

$container->when(AdminController::class)
    ->needs(LoggerInterface::class)
    ->give(DatabaseLogger::class);

// Multiple classes can share the same contextual binding
$container->when([ServiceA::class, ServiceB::class])
    ->needs(CacheInterface::class)
    ->give(RedisCache::class);
```

## Service aliasing

Create multiple names for the same service:

```php
// Create an alias
$container->alias('db', PDO::class);

// Access via alias or original name
$db1 = $container->get('db');        // Same instance
$db2 = $container->get(PDO::class);  // Same instance
```

## Related

- [Resolving services](resolution.md) — `get()`, `make()`, `getOrFail()`
- [PHP 8 attributes](attributes.md) — `#[Inject]` overrides a binding per parameter
