# PHP 8 Attributes

[← Back to index](../README.md#documentation)

Use attributes for declarative dependency configuration.

## `#[Inject]` — specify implementation

Override type hints to inject a specific implementation:

```php
use Gacela\Container\Attribute\Inject;

class NotificationService {
    public function __construct(
        #[Inject(EmailLogger::class)]
        private LoggerInterface $logger,
    ) {}
}

// EmailLogger will be injected even if LoggerInterface is bound to FileLogger
$service = $container->get(NotificationService::class);
```

## `#[Singleton]` — single instance

Mark a class to be instantiated only once:

```php
use Gacela\Container\Attribute\Singleton;

#[Singleton]
class DatabaseConnection {
    public function __construct(private string $dsn) {}
}

$conn1 = $container->get(DatabaseConnection::class);
$conn2 = $container->get(DatabaseConnection::class);
// $conn1 === $conn2 (same instance)
```

> The same behaviour is available at runtime, without an attribute, via
> [`singleton()`](bindings.md#fluent-registration).

## `#[Factory]` — new instances

Always create fresh instances:

```php
use Gacela\Container\Attribute\Factory;

#[Factory]
class RequestContext {
    public function __construct(private LoggerInterface $logger) {}
}

$ctx1 = $container->get(RequestContext::class);
$ctx2 = $container->get(RequestContext::class);
// $ctx1 !== $ctx2 (different instances)
```

**Note:** Attribute checks are cached internally, so repeated instantiations of
the same class avoid repeated reflection.

## `#[Lazy]`

Defers construction until the instance is first used.

```php
use Gacela\Container\Attribute\Lazy;

#[Lazy]
final class ReportGenerator
{
    public function __construct(private Database $db) {}
}

$report = $container->get(ReportGenerator::class);
// Nothing built yet — not the generator, not the Database behind it.

$report->run();  // constructed now, on first property access
```

What you get back is a **real instance** of the class, not a proxy subclass:
`$report::class` is `ReportGenerator` and `instanceof` behaves normally. There is
no generated proxy class and no extra dependency — this uses PHP's native lazy
objects.

Dependencies are resolved *inside* the initializer, so a lazy service that is
never touched costs nothing to build, and neither does its subtree.

### What triggers initialization

Property access. A method that reads no properties never needs the constructor,
so it will not trigger one:

```php
$service->staticGreeting();   // returns a constant: still uninitialized
$service->db;                 // touches state: constructs now
```

### Combining with other attributes

- `#[Singleton] #[Lazy]` — one shared instance, constructed on first touch
- `#[Factory] #[Lazy]` — a fresh lazy instance per resolution

### PHP version

Native lazy objects require **PHP 8.4**. On 8.3 a `#[Lazy]` class is constructed
eagerly instead. That is unobservable apart from the timing, so the attribute is
safe to use across both.
