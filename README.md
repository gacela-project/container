# Gacela Container

<p align="center">
  <a href="https://github.com/gacela-project/container/actions">
    <img src="https://github.com/gacela-project/container/workflows/CI/badge.svg" alt="GitHub Build Status">
  </a>
  <a href="https://scrutinizer-ci.com/g/gacela-project/container/?branch=main">
    <img src="https://scrutinizer-ci.com/g/gacela-project/container/badges/quality-score.png?b=main" alt="Scrutinizer Code Quality">
  </a>
  <a href="https://scrutinizer-ci.com/g/gacela-project/container/?branch=main">
    <img src="https://scrutinizer-ci.com/g/gacela-project/container/badges/coverage.png?b=main" alt="Scrutinizer Code Coverage">
  </a>
  <a href="https://shepherd.dev/github/gacela-project/container">
    <img src="https://shepherd.dev/github/gacela-project/container/coverage.svg" alt="Psalm Type-coverage Status">
  </a>
  <a href="https://github.com/gacela-project/container/blob/master/LICENSE">
    <img src="https://img.shields.io/badge/License-MIT-green.svg" alt="MIT Software License">
  </a>
</p>

A minimalistic, PSR-11 compliant dependency injection container with automatic constructor injection and zero configuration.

## Features

- 🚀 **Zero Configuration**: Automatic constructor injection without verbose setup
- 🔄 **Circular Dependency Detection**: Clear error messages when dependencies form a loop
- 📦 **PSR-11 Compliant**: Standard container interface for interoperability
- ⚡ **Performance Optimized**: Built-in caching and warmup capabilities
- 🔍 **Introspection**: Debug and inspect container state easily
- 🎯 **Type Safe**: Requires type hints for reliable dependency resolution

## Installation

```bash
composer require gacela-project/container
```

## Quick Start

### Basic Usage

```php
use Gacela\Container\Container;

// Simple auto-wiring
$container = new Container();
$instance = $container->get(YourClass::class);
```

### With Bindings

Map interfaces to concrete implementations:

```php
$bindings = [
    LoggerInterface::class => FileLogger::class,
    CacheInterface::class => new RedisCache('localhost'),
    ConfigInterface::class => fn() => loadConfig(),
];

$container = new Container($bindings);
$logger = $container->get(LoggerInterface::class); // Returns FileLogger
```

## How It Works

The container automatically resolves dependencies based on type hints:

- **Primitive types**: Uses default values (must be provided)
- **Classes**: Instantiates and resolves dependencies recursively
- **Interfaces**: Resolves using bindings defined in the container

### Example

```php
class UserService {
    public function __construct(
        private UserRepository $repository,
        private LoggerInterface $logger,
    ) {}
}

class UserRepository {
    public function __construct(private PDO $pdo) {}
}

// Setup
$bindings = [
    LoggerInterface::class => FileLogger::class,
    PDO::class => new PDO('mysql:host=localhost;dbname=app', 'user', 'pass'),
];

$container = new Container($bindings);

// Auto-resolves UserService -> UserRepository -> PDO
$service = $container->get(UserService::class);
```

## Advanced Features

### Factory Services

Create new instances on every call:

```php
$factory = $container->factory(fn() => new TempFile());
$container->set('temp_file', $factory);

$file1 = $container->get('temp_file'); // New instance
$file2 = $container->get('temp_file'); // Different instance
```

### Extending Services

Wrap or modify services (even before they're created):

```php
$container->set('logger', fn() => new FileLogger('/var/log/app.log'));

$container->extend('logger', function ($logger, $container) {
    return new LoggerDecorator($logger);
});
```

### Protecting Closures

Prevent closures from being executed:

```php
$closure = fn() => 'Hello World';
$container->set('greeting', $container->protect($closure));

$result = $container->get('greeting'); // Returns the closure itself
```

### Resolving Callables

Automatically inject dependencies into any callable:

```php
$result = $container->resolve(function (LoggerInterface $logger, CacheInterface $cache) {
    $logger->info('Cache cleared');
    return $cache->clear();
});
```

### Service Introspection

Debug and inspect container state:

```php
// Get all registered service IDs
$services = $container->getRegisteredServices();

// Check if service is a factory
if ($container->isFactory('temp_file')) {
    // Returns new instance each time
}

// Check if service is frozen (accessed)
if ($container->isFrozen('logger')) {
    // Cannot be modified anymore
}

// Get all bindings
$bindings = $container->getBindings();
```

### Performance Optimization

Pre-resolve dependencies for faster runtime:

```php
// During application bootstrap
$container->warmUp([
    UserService::class,
    OrderService::class,
    PaymentProcessor::class,
]);

// Later requests benefit from cached dependency resolution
$service = $container->get(UserService::class); // Faster!
```

## API Reference

### Container Methods

| Method | Description |
|--------|-------------|
| `get(string $id): mixed` | Retrieve or create a service |
| `has(string $id): bool` | Check if service exists |
| `set(string $id, mixed $instance): void` | Register a service |
| `remove(string $id): void` | Remove a service |
| `resolve(callable $callable): mixed` | Execute callable with dependency injection |
| `factory(Closure $instance): Closure` | Mark service as factory (new instance each time) |
| `extend(string $id, Closure $instance): Closure` | Wrap/modify a service |
| `protect(Closure $instance): Closure` | Prevent closure execution |
| `getRegisteredServices(): array` | Get all service IDs |
| `isFactory(string $id): bool` | Check if service is a factory |
| `isFrozen(string $id): bool` | Check if service is frozen |
| `getBindings(): array` | Get all bindings |
| `warmUp(array $classNames): void` | Pre-resolve dependencies |

### Static Methods

```php
// Quick instantiation without container setup
$instance = Container::create(YourClass::class);
```

## Best Practices

### 1. Use Constructor Injection

```php
// Good
class UserController {
    public function __construct(
        private UserService $userService,
        private LoggerInterface $logger
    ) {}
}

// Avoid setter injection (not supported)
```

### 2. Always Use Type Hints

```php
// Good - type hint required
public function __construct(LoggerInterface $logger) {}

// Bad - will throw exception
public function __construct($logger) {}
```

### 3. Provide Default Values for Scalars

```php
// Good
public function __construct(
    UserRepository $repo,
    int $maxRetries = 3,
    string $env = 'production'
) {}

// Bad - scalars without defaults cannot be resolved
public function __construct(string $apiKey) {} // Exception!
```

### 4. Use Bindings for Interfaces

```php
// Always bind interfaces to implementations
$bindings = [
    LoggerInterface::class => FileLogger::class,
    CacheInterface::class => RedisCache::class,
];
```

### 5. Warm Up in Production

```php
// In your bootstrap file
$container->warmUp([
    // List frequently used services
    UserService::class,
    AuthService::class,
    Router::class,
]);
```

## Error Handling

The container provides clear, actionable error messages with helpful suggestions:

### Missing Type Hint
```
No type hint found for parameter '$logger'.
Type hints are required for dependency injection to work properly.

Add a type hint to the parameter, for example:
  public function __construct(YourClass $logger) { ... }
```

### Circular Dependency
```
Circular dependency detected: ClassA -> ClassB -> ClassC -> ClassA

This happens when classes depend on each other in a loop.
Consider using setter injection or the factory pattern to break the cycle.
```

### Unresolvable Scalar
```
Unable to resolve parameter of type 'string' in 'UserService'.
Scalar types (string, int, float, bool, array) cannot be auto-resolved.

Provide a default value for the parameter:
  public function __construct(string $param = 'default') { ... }
```

### Service Not Found (with suggestions)
```
No concrete class was found that implements:
"App\LogerInterface"
Did you forget to bind this interface to a concrete class?

Did you mean one of these?
  - App\LoggerInterface
  - App\Service\LoggerInterface

You might find some help here: https://gacela-project.com/docs/bootstrap/#bindings
```

## Requirements

- PHP >= 8.1
- PSR-11 Container Interface

## Testing

```bash
composer test          # Run tests
composer quality       # Run static analysis
composer test-coverage # Generate coverage report
```

## Real-World Example

See how it's used in the [Gacela Framework](https://github.com/gacela-project/gacela/blob/main/src/Framework/ClassResolver/AbstractClassResolver.php#L142)

## License

MIT License. See [LICENSE](LICENSE) file for details.

