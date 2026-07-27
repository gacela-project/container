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

### On properties

`#[Inject]` also works on a property, for classes whose constructor is not yours
to change:

```php
final class ReportBuilder extends FrameworkBaseClass
{
    #[Inject]
    private LoggerInterface $logger;

    #[Inject(RedisCache::class)]
    private CacheInterface $cache;
}
```

Private, protected and inherited properties are all supported, including private
properties declared on a parent class. Static properties are ignored. A promoted
constructor parameter is injected by the constructor, never twice.

**Constructor injection remains the default, and this is not an equal
alternative.** Dependencies stop being visible in the signature, the property
cannot be `readonly`, and the object is briefly in an invalid state after `new`.
Reach for it when the constructor is out of your hands — a framework base class,
or legacy code — not as a matter of taste.

Before using it, check whether [`afterResolving()`](services.md) already covers
the case. It runs after resolution with the instance and the container in hand,
and needs no new machinery.

#### It is not a way to model cycles

The most common reason people reach for property injection is to break a
circular dependency. That does not work here, deliberately:

```php
final class A { #[Inject] public B $b; }
final class B { #[Inject] public A $a; }

$container->get(A::class);   // CircularDependencyException
```

Property injection runs inside the same resolution stack as constructor
injection, so a cycle reached through a property is reported exactly like any
other. `CircularDependencyException` and its resolution chain are a feature of
this library, and this attribute does not open a hole in them.

#### Errors

| Property | Result |
|---|---|
| `readonly` | `DependencyInvalidArgumentException` — only the declaring class may write it |
| Untyped | `DependencyInvalidArgumentException` — nothing to resolve |
| Scalar type (`string`, `int`, …) | `DependencyInvalidArgumentException` — pass it through the constructor |

#### Cost

Nothing at all if you do not use it: classes with no `#[Inject]` property never
enter the injection path, and the reflection scan is memoized per class for the
lifetime of the process rather than per container.

When you do use it, expect roughly **+15%** on that class's resolution against
the constructor equivalent (1.099μs vs 0.959μs), which is the cost of
`ReflectionProperty::setValue()`.

A class with injected properties is skipped by
[`writeCompiledFactories()`](performance.md) — a generated `new` expression
cannot assign them — and resolves through the normal path instead.

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
