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
$stats = $container->getStats();
/*
[
    'registered_services' => 42,
    'frozen_services' => 15,
    'factory_services' => 3,
    'bindings' => 8,
    'cached_dependencies' => 25,
    'memory_usage' => '2.34 MB'
]
*/
```

## Related

- [Bindings & registration](bindings.md)
- [Performance & compilation](performance.md)
