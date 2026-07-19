# API Reference

[← Back to index](../README.md#documentation)

## Container methods

| Method | Description |
|--------|-------------|
| `get(string $id): mixed` | Retrieve or create a service |
| `getOrFail(string $id): mixed` | Like `get()`, but throws when the id resolves to `null` |
| `make(string $className, array $parameters = []): object` | Resolve a class to a typed, non-null instance; `$parameters` override constructor args by name |
| `has(string $id): bool` | Check if a service exists (instance registry) |
| `bound(string $id): bool` | Check if a binding or instance exists (alias-aware) |
| `bindIf(string $abstract, string\|callable\|object $concrete): void` | Bind only if not already bound |
| `singletonIf(string $abstract, string\|callable\|object\|null $concrete = null): void` | Singleton-bind only if not already bound |
| `bind(string $abstract, string\|callable\|object $concrete): void` | Register a binding after construction |
| `singleton(string $abstract, string\|callable\|object\|null $concrete = null): void` | Register a binding resolved once and reused |
| `set(string $id, mixed $instance): void` | Register a service |
| `remove(string $id): void` | Remove a service |
| `resolve(callable $callable, array $parameters = []): mixed` | Execute a callable with dependency injection; `$parameters` override args by name |
| `factory(Closure $instance): Closure` | Mark a service as a factory (new instance each time) |
| `extend(string $id, Closure $instance): Closure` | Wrap/modify a service |
| `protect(Closure $instance): Closure` | Prevent closure execution |
| `getRegisteredServices(): array` | Get all service IDs |
| `isFactory(string $id): bool` | Check if a service is a factory |
| `isFrozen(string $id): bool` | Check if a service is frozen |
| `getBindings(): array` | Get all bindings |
| `warmUp(array $classNames): void` | Pre-resolve dependencies |
| `compile(array $classNames): array` | Warm up and return compiled constructor plans |
| `writeCompiledCache(array $classNames, string $file): void` | Compile plans and write them to a PHP cache file |
| `alias(string $alias, string $id): void` | Create an alias for a service |
| `tag(string\|array $ids, string $tag): void` | Group service ids under a tag (accumulates, dedupes) |
| `tagged(string $tag): iterable` | Lazily resolve all services under a tag, in insertion order |
| `getStats(): array` | Get container statistics |
| `getDependencyTree(string $className): array` | List the classes a given class depends on |
| `when(string\|array $concrete): ContextualBindingBuilder` | Define contextual bindings for specific classes (`needs()` accepts a type or a `$paramName`) |

## Static methods

```php
// Quick instantiation without container setup
$instance = Container::create(YourClass::class);

// Load compiled constructor plans from a cache file
$plans = Container::loadCompiledCache(__DIR__ . '/cache/container.php');
$container = new Container($bindings, [], $plans);
```

## Attributes

| Attribute | Target | Description |
|-----------|--------|-------------|
| `#[Inject(class-string)]` | parameter | Inject a specific implementation |
| `#[Singleton]` | class | Instantiate once and reuse |
| `#[Factory]` | class | Create a fresh instance every time |

See [attributes](attributes.md) for examples.
