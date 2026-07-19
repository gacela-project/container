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

// Avoid setter injection (not supported)
```

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
