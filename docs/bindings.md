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

A **string concrete is always read as a class name**, never as a callable. That
matters in the one case where it is ambiguous: PHP keeps classes and functions
in separate tables, so `App\Mailer` can name both, and a container that asked
`is_callable()` first would call the function and hand back whatever it
returned. Pass a closure when you want something invoked.

### Container-aware closures

Binding closures receive the container as their first argument, so a factory can
compose from other services. Existing zero-argument closures keep working:

```php
$container->bind(Mailer::class, fn(Container $c) => new SmtpMailer($c->get(Config::class)));

$container->singleton(Report::class, fn(Container $c) => new Report($c->make(Clock::class)));

// Still valid — the extra argument is simply ignored
$container->bind(Greeter::class, fn() => new Greeter('hi'));
```

This applies to constructor bindings, `singleton()` closures, and both type- and
name-based contextual `give()` closures.

### Conditional registration

Register defaults only when nothing is bound yet — useful for packages that
provide overridable bindings:

```php
$container->bound(LoggerInterface::class);   // true if a binding OR instance exists (alias-aware)

$container->bindIf(LoggerInterface::class, FileLogger::class);      // no-op if already bound
$container->singletonIf(CacheInterface::class, ArrayCache::class);  // no-op if already bound
```

`bound()` differs from PSR-11 `has()`: `has()` reports whether an id can be
retrieved from the instance registry, while `bound()` also accounts for
bindings.

### Deferred registration

`lazy()` registers a binding whose construction is put off until the service is
first used:

```php
$container->lazy(ReportGenerator::class);                              // the class itself, deferred
$container->lazy(ReportGeneratorInterface::class, PdfReports::class);  // an abstract, lazily bound
$container->lazy(PdfReports::class, fn(Container $c) => new PdfReports($c->get(Database::class)));
```

Unlike a plain `bind()` closure — which runs the moment the id resolves — the
closure form runs on first touch. What comes back is a real instance of the
target class, not a proxy subclass.

The target must be a concrete, instantiable class; anything else throws a
`ContainerException`. Combine it with `singleton()` for one shared instance built
on first touch. See [`#[Lazy]`](attributes.md#lazy) for what triggers
initialization and for the PHP 8.4 requirement.

`lazy()` is declared on
[`ContainerInterface`](api-reference.md#what-the-interface-guarantees), so
type-hinting the interface is enough to reach it.

### Registration as data

Everything above has an array form, so wiring can be shipped and overridden as
configuration instead of code:

```php
$container->load([
    LoggerInterface::class => FileLogger::class,
    Database::class => ['singleton' => DatabasePool::class],
    'db.dsn' => ['value' => 'pgsql://localhost/app'],
]);

$container->loadFile(__DIR__ . '/config/services.php');   // or .json, .yaml
```

See [definitions](definitions.md) for every entry form, file loading, and
environment layering.

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

### Named (scalar) contextual bindings

`needs()` also accepts a `$`-prefixed **parameter name**, to inject a scalar,
array, object, or closure into a specific class by name — no constructor default
required:

```php
$container->when(ApiClient::class)
    ->needs('$apiKey')
    ->give(fn() => getenv('API_KEY'));   // closure is invoked per resolution

$container->when(ReportService::class)
    ->needs('$timeoutSeconds')
    ->give(30);
```

The binding is scoped to the class named in `when()`; the same parameter name on
another class is unaffected.

`null` is a value like any other here — "this consumer gets no logger" is a real
answer, and a nullable parameter with no default has no other way to say it:

```php
$container->when(ReportService::class)
    ->needs('$logger')
    ->give(null);
```

For a **type** need it is refused, with a message saying so. Not binding a type
already means "nothing is bound", so `->needs(LoggerInterface::class)->give(null)`
could only ever be a mistake — and a silent one, since it would behave exactly as
though the call had not been made. Express an optional dependency on the class
instead:

```php
public function __construct(private ?LoggerInterface $logger = null) { ... }
```

## Service tagging

Group services under a tag and resolve them together — ideal for collecting
handlers, plugins, or strategies:

```php
$container->tag([JsonExport::class, CsvExport::class], 'exporters');
$container->tag(XmlExport::class, 'exporters'); // append a single id

foreach ($container->tagged('exporters') as $exporter) {
    $exporter->export($data);
}
```

- `tag()` accepts a single id or a list; repeated calls accumulate and dedupe.
- `tagged()` resolves ids **lazily** in insertion order (a generator), so
  instances are built only as you iterate.
- An unknown tag yields nothing.

### Keyed tags

Pass a map and the entries become addressable — a command bus, a router or a
strategy map wants *the* handler for `'email'`, not all of them:

```php
$container->tag([
    'email' => EmailHandler::class,
    'sms' => SmsHandler::class,
], 'notification.handlers');

$handler = $container->taggedByKey('notification.handlers', 'sms');
```

- Only the entry asked for is built. Registering a hundred handlers constructs
  none of them.
- The instance comes from the container's own cache, so a `singleton()` still
  lives in exactly one place — a keyed tag is a lookup table of ids, never a
  second place instances are kept.
- Registering a key again replaces it, which makes per-environment layering a
  matter of registration order.
- `taggedKeys($tag)` lists the keys, in insertion order. Entries registered
  without one are not listed: there is no key to ask with.
- An unknown key throws a `ContainerException` naming the keys that do exist
  (with a did-you-mean for near misses) rather than returning `null` — a router
  asking for a handler that was never registered is a misconfiguration. Check
  `taggedKeys()` first when the key is genuinely optional.
- Keys and plain ids live in one tag. `tagged()` yields keyed entries under
  their key and unkeyed ones under their position, so existing loops are
  unchanged.
- A scope inherits keyed entries and can override one for itself, exactly as it
  shadows a binding. See [scopes](scopes.md).

#### A keyed tag does not pin an instance

Asked directly, because a dispatch table built on top of one looks identical
from outside: **no**, `taggedByKey()` does not promise the same instance twice.
It hands back whatever `get()` hands back, so the lifetime is whatever the
*registration* said — a `singleton()` is shared, a `bind()` or a `#[Factory]`
class is rebuilt per call.

That is deliberate. A tag that pinned instances would change the lifetime of a
service depending on whether it happens to be tagged, which is the "second place
instances live" this design avoids — and it would be silent, since nothing about
`taggedByKey('handlers', 'sms')` suggests it overrides how `SmsHandler` was
registered.

If you want one handler per key for the container's lifetime, say so where
lifetimes are said:

```php
$container->singleton(SmsHandler::class);
$container->tag(['sms' => SmsHandler::class], 'notification.handlers');
```

Now the key resolves to one instance, because the *service* is shared — and it
is shared everywhere, not only through the tag. That composes with scopes,
`extend()` and freezing; a tag-local cache would not.

## Service aliasing

Create multiple names for the same service:

```php
// Create an alias
$container->alias('db', PDO::class);

// Access via alias or original name
$db1 = $container->get('db');        // Same instance
$db2 = $container->get(PDO::class);  // Same instance
```

An alias whose *name* is a class or interface is followed for a constructor
parameter typed as it too, so `alias(Clock::class, 'clock.frozen')` redirects
every injection of `Clock` — see
[a registration answers a constructor parameter too](resolution.md#a-registration-answers-a-constructor-parameter-too).

## Related

- [Resolving services](resolution.md) — `get()`, `make()`, `getOrFail()`
- [PHP 8 attributes](attributes.md) — `#[Inject]` overrides a binding per parameter
