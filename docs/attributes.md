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

### On methods

`#[Inject]` on a public method has the container call it after construction:

```php
final class ReportBuilder extends FrameworkBaseClass
{
    private Clock $clock;

    #[Inject]
    public function setClock(Clock $clock): void
    {
        $this->clock = $clock;
    }
}
```

Arguments resolve through the same path a constructor's parameters do, so
contextual bindings, defaults and a nested `#[Inject]` naming an implementation
all behave identically:

```php
#[Inject]
public function setRepository(
    #[Inject(DatabaseRepository::class)] RepositoryInterface $repository,
): void { ... }
```

Inherited methods are called. The constructor is never treated as one of these,
whatever it is annotated with.

#### What a property cannot do

Property injection covers "the constructor is not mine to change" only for
fields you can write to, and it writes the field directly. A method can do two
things it cannot:

- **run validation or derive state**, since it is a method body and not an
  assignment;
- **be declared on an interface** — a `ClockAwareInterface` with `setClock()` is
  a shape a property cannot express at all.

It is also the honest answer for two collaborators that need each other *after*
construction but not during it. That is not a cycle in the resolution sense and
a constructor cannot express it.

#### Ordering

Fixed, because it is observable and someone will depend on it:

1. the constructor
2. `#[Inject]` properties
3. `#[Inject]` methods, in declaration order

So a setter can read anything the constructor or the properties set.

#### Errors

Refused **by name**, rather than skipped — an annotation that is silently
ignored means a dependency that never arrived and nothing anywhere saying so:

| Method | Result |
|---|---|
| `static` | `DependencyInvalidArgumentException` — injection calls it on an instance |
| `private` / `protected` | `DependencyInvalidArgumentException` — the container calls from outside |
| Scalar or untyped parameter | `DependencyInvalidArgumentException`, exactly as on a constructor |

#### Cost

Nothing if you do not use it: the scan is memoized per class for the life of the
process, and a class with no `#[Inject]` method never enters the call path.

Under [`#[Lazy]`](#lazy) the calls sit inside the initializer with the
constructor, so a lazy service still defers them until first touch. A class with
injected methods is skipped by [`writeCompiledFactories()`](performance.md) —
a `new` expression cannot make the calls — with the reason `InjectedMethod`.

## Re-presenting these under your own namespace

The four attributes are **not `final`**, and every read the container makes
passes `ReflectionAttribute::IS_INSTANCEOF`. So a package or framework wrapping
this container can offer them under its own name without its users importing a
vendor namespace:

```php
namespace App\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final class Inject extends \Gacela\Container\Attribute\Inject
{
}
```

`#[App\Attribute\Inject]` is then honoured exactly as `#[Gacela\Container\Attribute\Inject]`
is, including the optional implementation argument. The same works for
`#[Singleton]`, `#[Factory]` and `#[Lazy]`.

Repeat the `#[Attribute(...)]` declaration on the subclass with the targets you
want — PHP does not inherit it — and keep the targets within the parent's.

An exact-FQN match, which is what this used to do, follows **neither** a
subclass nor a `class_alias()`. That failure is silent: the parameter is simply
not injected and nothing says why.

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

That holds wherever the service appears, not just at the top of a `get()` call: a
lazy class injected into another class's constructor is handed over uninitialized,
so the holder can be built without paying for it.

### What triggers initialization

Property access. A method that reads no properties never needs the constructor,
so it will not trigger one:

```php
$service->staticGreeting();   // returns a constant: still uninitialized
$service->db;                 // touches state: constructs now
```

### Without the attribute

`lazy()` does the same thing from the outside, for classes you cannot annotate
and for cases where laziness belongs to the app rather than the class:

```php
// A vendor class, made lazy without touching it
$container->lazy(ReportGenerator::class);

// An abstract, lazily bound to a concrete
$container->lazy(ReportGeneratorInterface::class, PdfReportGenerator::class);

// A closure binding that runs on first touch instead of on resolution
$container->lazy(PdfReportGenerator::class, fn(Container $c) => new PdfReportGenerator(
    $c->get(Database::class),
));
```

The first two forms produce the same lazy ghost as the attribute. The closure
form produces a lazy **proxy** instead — the closure, not the constructor, makes
the instance — but it is still a native lazy object of the right class, so
`instanceof` and `::class` behave exactly as above.

The target must be a concrete, instantiable class either way: a lazy instance has
to be an instance of *something*. `lazy(SomeInterface::class)` with no concrete
throws a `ContainerException`.

Registering both `#[Lazy]` and `lazy()` for the same class is not an error, and
`singleton()` combines with it the way `#[Singleton] #[Lazy]` does — one shared
instance, constructed on first touch. A [scope](scopes.md) inherits the lazy
registrations its parent had when the scope was created.

An uninitialized lazy instance keeps the container that produced it alive, since
first touch is what resolves its dependencies — the one thing that outlives the
container reference the caller drops. Touching it releases the hold. This matters
only if you hand lazy instances out and drop the container while they are still
untouched; see [disposal](scopes.md#disposal-is-dropping-the-reference).

See [bindings](bindings.md#deferred-registration).

### Combining with other attributes

- `#[Singleton] #[Lazy]` — one shared instance, constructed on first touch
- `#[Factory] #[Lazy]` — a fresh lazy instance per resolution

### PHP version

Native lazy objects require **PHP 8.4**. On 8.3 a `#[Lazy]` class is constructed
eagerly instead. That is unobservable apart from the timing, so the attribute is
safe to use across both.
