# Best Practices

[← Back to index](../README.md#documentation)

## 1. Use constructor injection

```php
// Good
class UserController {
    public function __construct(
        private UserService $userService,
        private LoggerInterface $logger,
    ) {}
}

```

## When to reach for setter injection

`#[Inject]` on a [property](attributes.md#on-properties) or a
[method](attributes.md#on-methods) exists, and constructor injection is still
the default. Setter injection permits partially-constructed objects, which is
what the rest of this page steers away from: between `new` and the setter the
object is valid to PHP and not to you.

Reach for it when the constructor is genuinely not yours:

- a framework base class, or vendor code you cannot change;
- a dependency that is truly optional, where a nullable constructor parameter
  would force every caller to think about it;
- two collaborators that need each other after construction but not during it —
  a constructor cannot express that, and a setter can;
- an injection point declared on an interface, which a property cannot be.

Not for taste, and not to break a dependency cycle: a cycle reached through a
property or a setter still raises `CircularDependencyException`, deliberately.

## 2. Always use type hints

```php
// Good - type hint required
public function __construct(LoggerInterface $logger) {}

// Bad - will throw an exception
public function __construct($logger) {}
```

## 3. Provide default values for scalars

```php
// Good
public function __construct(
    UserRepository $repo,
    int $maxRetries = 3,
    string $env = 'production',
) {}

// Bad - scalars without defaults cannot be resolved
public function __construct(string $apiKey) {} // Exception!
```

## 4. Use bindings for interfaces

```php
// Always bind interfaces to implementations
$bindings = [
    LoggerInterface::class => FileLogger::class,
    CacheInterface::class => RedisCache::class,
];
```

## 5. Warm up (or compile) in production

```php
// In your bootstrap file
$container->warmUp([
    // List frequently used services
    UserService::class,
    AuthService::class,
    Router::class,
]);
```

For a cross-request speed-up, see the
[compiled container cache](performance.md#compiled-container-cache).
