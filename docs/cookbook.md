# Cookbook

[← Back to index](../README.md#documentation)

Short answers to "how do I do X". Every recipe here was executed against the
current build before being written down.

## Swap an implementation in tests

Bind the fake over the real one. Nothing in production wiring changes.

```php
$container = new Container([
    MailerInterface::class => InMemoryMailer::class,
]);

$service = $container->get(OrderService::class);
```

For a single test, `bind()` after construction reads better:

```php
$container = new Container();
$container->bind(MailerInterface::class, InMemoryMailer::class);
```

## Pass configuration values to a service

Scalars cannot be autowired. Bind them by parameter name, scoped to the class
that needs them:

```php
$container->when(ApiClient::class)
    ->needs('$timeout')
    ->give(30);

$container->when(ApiClient::class)
    ->needs('$baseUrl')
    ->give('https://api.example.com');
```

## Give two classes different implementations of one interface

```php
$container->when(OrderReport::class)
    ->needs(StorageInterface::class)
    ->give(S3Storage::class);

$container->when(TempExport::class)
    ->needs(StorageInterface::class)
    ->give(LocalStorage::class);
```

## Let a package register its own services

The Symfony answer to this is a compiler pass. There is nothing to pass over
here — an autowiring container has no definition set until things resolve — so
the answer is plainer: a package exposes definitions, and the application loads
them.

```php
// vendor/acme/mailer/config/container.php
return [
    Acme\Mailer\TransportInterface::class => Acme\Mailer\SmtpTransport::class,
    'acme.mailer' => ['singleton' => Acme\Mailer\Mailer::class, 'tags' => ['notifiers']],
];
```

```php
$container->loadFile(__DIR__ . '/../vendor/acme/mailer/config/container.php');
$container->loadFile(__DIR__ . '/config/services.php');   // yours wins
```

Later keys override earlier ones, so the application always gets the last word,
and load order is the whole precedence rule — no priority integers, no pass
ordering. A package needing logic rather than data exposes a function instead:

```php
return static function (Container $container): void {
    $container->bind(TransportInterface::class, SmtpTransport::class);
    $container->when(Mailer::class)->needs('$retries')->give(3);
};
```

This is explicit, ordered and debuggable — you can `var_dump` the array — which
a pass that rewrites a definition set behind you is not. See
[definitions](definitions.md).

## Build a plugin system

Tag the implementations, then resolve them together. `tagged()` is lazy — it
resolves each service as you iterate, in registration order.

```php
$container->bind(SlackNotifier::class, SlackNotifier::class);
$container->bind(EmailNotifier::class, EmailNotifier::class);

$container->tag([SlackNotifier::class, EmailNotifier::class], 'notifiers');

foreach ($container->tagged('notifiers') as $notifier) {
    $notifier->send($message);
}
```

## Route a message to one handler

Same mechanism, keyed — a command bus or router needs *the* handler for a
message type, not all of them. Only the one asked for is built:

```php
$container->tag([
    'order.placed' => OrderPlacedHandler::class,
    'order.shipped' => OrderShippedHandler::class,
], 'handlers');

$container->taggedByKey('handlers', $message->type())->handle($message);
```

An unknown key throws, naming the keys that exist. Use `taggedKeys('handlers')`
when a message having no handler is a normal outcome rather than a
misconfiguration. See [keyed tags](bindings.md#keyed-tags).

## Decorate a third-party service

`extend()` wraps a single binding. It works even before the service is defined —
the extension is scheduled and applied when the service is set.

```php
$container->set('http.client', new GuzzleClient());

$container->extend('http.client', static function (object $client): object {
    return new LoggingClient($client);
});
```

## Run code after a service resolves

When you want a hook rather than a wrapper:

```php
$container->afterResolving(Connection::class, static function (object $connection): void {
    $connection->setTimezone('UTC');
});
```

## Share one instance for the whole request

```php
$container->singleton(Connection::class);
```

Or declare it on the class, so every container shares the intent:

```php
use Gacela\Container\Attribute\Singleton;

#[Singleton]
final class Connection {}
```

The opposite — a fresh instance every time — is `#[Factory]`.

## Give each request its own instances, under a long-running runtime

Under Swoole, RoadRunner or FrankenPHP the process outlives the request, so a
`singleton()` is shared by every request rather than scoped to one. Use a
[scope](scopes.md) for the per-request half:

```php
$app = new Container($bindings);
$app->singleton(Database::class);   // one instance for the whole process
$app->warmUp([Router::class]);      // reflection done once, at boot

foreach ($server->requests() as $request) {
    $scope = $app->createScope();
    $scope->set(Request::class, $request);

    $handler->handle($scope);
    // $scope goes out of range here, and takes its instances with it
}
```

The scope resolves anything it does not hold through `$app`, and nothing is
re-registered per request. Note the split: `singleton()` is what makes
`Database` shared across requests — `warmUp()` only caches *reflection*, so a
class that is merely warmed up is still built once per scope.

See [scopes](scopes.md) for what a scope shares with its parent and what it
keeps to itself.

## Build a service from other services

Binding closures receive the container:

```php
$container->singleton(ReportBuilder::class, static function (ContainerInterface $c): ReportBuilder {
    return new ReportBuilder(
        $c->get(Database::class),
        $c->get(TemplateEngine::class),
    );
});
```

## Skip building something expensive that may go unused

```php
use Gacela\Container\Attribute\Lazy;

#[Lazy]
final class ReportGenerator
{
    public function __construct(private Database $db) {}
}
```

Resolving it costs nothing; the constructor runs on first property access. See
[performance](performance.md).

## Override a constructor argument for one call

```php
$service = $container->make(ReportService::class, ['format' => 'csv']);
```

Overrides apply to the top-level constructor only, and the instance is always
built fresh.

## Wire a container per environment

Keep the shared wiring in one file and the per-environment differences in
another. Later keys win, so layering is just load order:

```php
$container = new Container();
$container->loadFile(__DIR__ . '/config/services.php');
$container->loadFile(__DIR__ . "/config/services.{$env}.php");
```

```php
// config/services.php
return [
    LoggerInterface::class => FileLogger::class,
    MailerInterface::class => ['singleton' => SmtpMailer::class],
    'db.dsn' => ['value' => getenv('DATABASE_URL')],
];

// config/services.test.php
return [
    MailerInterface::class => ['singleton' => NullMailer::class],
];
```

A package can ship its defaults the same way and let the application override
them. See [definitions](definitions.md).

## Speed up per-request bootstrap

Compile constructor plans in a build step, load them at runtime:

```php
// build step
$container = new Container($bindings);
$container->writeCompiledCache([UserService::class], __DIR__ . '/cache/container.php');

// runtime
$plans = Container::loadCompiledCache(__DIR__ . '/cache/container.php');
$container = new Container($bindings, [], $plans);
```

Regenerate the file whenever constructors change. If a deploy forgets to, the
entries whose class files moved on are dropped on load and those classes fall
back to reflection — see [staleness](performance.md#staleness).

## Work out why something resolves to the wrong thing

```php
$container->getBindings();                           // every abstract => concrete
$container->getDependencyTree(OrderService::class);  // what it pulls in, flat
$container->getRegisteredServices();                 // ids with a stored instance
$container->stats();                                 // counters, for debugging only
```

`getDependencyTree()` is a flat, deduplicated list — it answers *what* is
touched, not how. When the question is *why*, print the graph:

```php
echo $container->dependencyGraph(OrderService::class);

// App\OrderService
// ├── $repository: App\OrderRepository
// │   └── $db: App\Db
// └── $clock: App\SystemClock
```

Each node carries the constructor parameter it satisfies, so you can see which
class asked for what and how deep it sits. Bindings are already resolved, so an
interface shows the concrete it maps to. A cycle is marked `(cycle)` and cut
rather than thrown — a broken graph is exactly when you reach for this:

```php
$graph = $container->dependencyGraph(OrderService::class);

$graph->depth();      // 2
$graph->hasCycle();   // false
$graph->flatten();    // same list getDependencyTree() returns
```

`has()` and `bound()` answer different questions — see
[resolving services](resolution.md).

## Check whether something is registered

```php
$container->bound(MailerInterface::class);  // was it explicitly registered?
$container->has(MailerInterface::class);    // will get() resolve it?
```

`bindIf()` and `singletonIf()` register only when absent, which is how you
provide a default without stomping on a caller's choice:

```php
$container->bindIf(MailerInterface::class, SmtpMailer::class);
```

## Related

- [Bindings & registration](bindings.md)
- [Definitions as data](definitions.md)
- [Resolving services](resolution.md)
- [Scopes](scopes.md)
- [Error handling](error-handling.md)
