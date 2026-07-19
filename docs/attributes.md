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
