# Managing Services

[← Back to index](../README.md#documentation)

Beyond autowiring, services can be registered and decorated explicitly.

## Factory services

Create a new instance on every call:

```php
$factory = $container->factory(fn() => new TempFile());
$container->set('temp_file', $factory);

$file1 = $container->get('temp_file'); // New instance
$file2 = $container->get('temp_file'); // Different instance
```

## Extending services

Wrap or modify services, even before they are created:

```php
$container->set('logger', fn() => new FileLogger('/var/log/app.log'));

$container->extend('logger', function ($logger, $container) {
    return new LoggerDecorator($logger);
});
```

## Protecting closures

Prevent a closure from being executed on resolution (return it as-is):

```php
$closure = fn() => 'Hello World';
$container->set('greeting', $container->protect($closure));

$result = $container->get('greeting'); // Returns the closure itself
```

## Resolution hooks

Run logic after a service is resolved — configure it, decorate it, or register
handlers. Callbacks receive the resolved instance and the container, and run in
registration order:

```php
$container->afterResolving(Logger::class, function (Logger $logger, Container $c): void {
    $logger->pushHandler($c->get(StreamHandler::class));
});
```

When the id is a **class or interface**, the callback fires for every resolved
instance of it — so cross-cutting wiring is one registration rather than one per
implementation:

```php
$container->afterResolving(LoggerAwareInterface::class, function (object $s, Container $c): void {
    $s->setLogger($c->get(LoggerInterface::class));
});
```

Any other id matches exactly, so `afterResolving('db.primary', …)` fires for that
id and nothing else. Both kinds fire in registration order, interleaved.

Hooks fire for `get()`, `getOrFail()` and `make()`, including `make()` with
overridden arguments.

A callback that throws **removes the instance from the container**: a service
whose post-construction wiring failed should not be handed to the next caller as
though it had succeeded. The exception propagates either way.

## Introspection

Debug and inspect container state:

```php
// Get all registered service IDs
$services = $container->getRegisteredServices();

// Check if a service is a factory
if ($container->isFactory('temp_file')) {
    // Returns a new instance each time
}

// Check if a service is frozen (has been accessed)
if ($container->isFrozen('logger')) {
    // Cannot be modified anymore
}

// Get all bindings
$bindings = $container->getBindings();

// Get container statistics
$stats = $container->stats();

$stats->registeredServices;      // 42
$stats->frozenServices;          // 15
$stats->factoryServices;         // 3
$stats->bindings;                // 8
$stats->cachedDependencies;      // 25
$stats->memoryUsageBytes;        // 2453667 — an int, so it can be compared
$stats->memoryUsageFormatted();  // '2.34 MB'
```

**`memoryUsageBytes` is the whole PHP process, not this container.** It is
`memory_get_usage(true)`: the real memory the allocator has handed the process.
It moves when anything anywhere allocates, and two containers in the same
process report the same number. Every other field is a container-scoped counter,
so this one is the odd one out — read it as ambient context beside them, not as
what the container costs.

Measuring a single container's footprint would mean carrying accounting code on
the registration paths to feed a debug field, which is not a trade this library
makes. The field is renamed `processMemoryBytes` in 2.0, so the name says what
the value is.

`stats()` is on [`FullContainerInterface`](api-reference.md#what-the-interface-guarantees)
rather than `ContainerInterface`, because 1.x promises no method will be added
to the latter. The two merge at 2.0.

The older `getStats()` returns the same numbers as an array and keeps working for
the whole of 1.x. Prefer `stats()`: the array's shape is
[excluded from BC](backward-compatibility.md), and its memory figure is a
preformatted string that has to be parsed back before it can be used.

## Related

- [Bindings & registration](bindings.md)
- [Performance & compilation](performance.md)
