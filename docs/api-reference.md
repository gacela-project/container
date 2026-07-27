# API Reference

[← Back to index](../README.md#documentation)

## Public API

Every class in `src/` is marked either `@api` or `@internal`.

**`@api` — covered by semantic versioning.** These will not break within a major
version:

| Class | |
|---|---|
| `Container` | the container itself (`final`) |
| `ContainerInterface` | the contract to type-hint against |
| `ContextualBindingBuilder` | returned by `when()` |
| `Attribute\Inject`, `Attribute\Singleton`, `Attribute\Factory`, `Attribute\Lazy` | the PHP 8 attributes |
| `Exception\ContainerException` | |
| `Exception\CircularDependencyException` | |
| `Exception\DependencyNotFoundException` | |
| `Exception\DependencyInvalidArgumentException` | |

For the exceptions, the **class and its PSR-11 interface** are stable. Exception
*messages* are not — do not parse or assert on them.

**`@internal` — not covered.** `AliasRegistry`, `BindingResolver`,
`DependencyCacheManager`, `DependencyResolver`, `DependencyTreeAnalyzer`,
`FactoryManager`, `FuzzyMatcher`, `InstanceRegistry`, `PlanRegistry`, and
`TagRegistry` are implementation details of `Container`. They may change
signature, behaviour, or disappear entirely in **any** release, including a
patch. Do not import them.

## What the interface guarantees

Almost every instance method below is declared on `ContainerInterface`; the
handful that are not are marked `Container` only. Type-hint the interface unless
you need one of those.

`ContainerInterface` also extends `ArrayAccess`, so `$c[Id::class]`, `isset()`,
assignment, and `unset()` are part of the contract rather than a concrete-class
convenience.

Nine members live on `Container` alone. Three of them *cannot* be on an
interface: the constructor, and the two static methods `create()` and
`loadCompiledCache()`. The other six — `stats()`, `createScope()`, `provides()`,
`lazy()`, `writeCompiledFactories()` and `useCompiledFactories()` — are kept off
deliberately: 1.x promises nothing will be added to `ContainerInterface`, so they
move there in 2.0.

One caveat: `getStats()` is on the interface, but the **shape of the array it
returns is not covered by backward compatibility**. Treat it as debug output, or
use `stats()`, whose shape *is* covered.

## Container methods

| Method | Description |
|--------|-------------|
| `get(string $id): mixed` | Retrieve or create a service |
| `getOrFail(string $id): mixed` | Like `get()`, but throws when the id resolves to `null` |
| `make(string $className, array $parameters = []): object` | Resolve a class to a typed, non-null instance; `$parameters` override constructor args by name |
| `has(string $id): bool` | PSR-11: whether `get()` will resolve the id — includes autowirable classes |
| `afterResolving(string $id, Closure $callback): void` | Run a callback after the id is resolved (`$instance`, `$container`) |
| `bound(string $id): bool` | Whether the id was explicitly registered — a binding or a stored instance (alias-aware) |
| `provides(string $id): bool` | Whether this container or an ancestor already owns the id — a binding, a stored instance, or a resolved singleton. `Container` only |
| `bindIf(string $abstract, string\|callable\|object $concrete): void` | Bind only if not already bound |
| `singletonIf(string $abstract, string\|callable\|object\|null $concrete = null): void` | Singleton-bind only if not already bound |
| `bind(string $abstract, string\|callable\|object $concrete): void` | Register a binding after construction |
| `singleton(string $abstract, string\|callable\|object\|null $concrete = null): void` | Register a binding resolved once and reused |
| `lazy(string $abstract, string\|callable\|null $concrete = null): void` | Register a binding whose construction is deferred to first use. `Container` only — see [bindings](bindings.md#deferred-registration) |
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
| `writeCompiledFactories(array $classNames, string $file): array` | Generate `new` expressions for statically-decidable classes; returns those compiled. `Container` only |
| `useCompiledFactories(array $factories): void` | Use generated factories as a fast path. `Container` only |
| `alias(string $alias, string $id): void` | Create an alias for a service |
| `tag(string\|array $ids, string $tag): void` | Group service ids under a tag (accumulates, dedupes) |
| `tagged(string $tag): iterable` | Lazily resolve all services under a tag, in insertion order |
| `createScope(): Container` | A child container inheriting this one's registration without copying it. `Container` only — see [scopes](scopes.md) |
| `stats(): ContainerStats` | Container statistics as a readonly object; shape is covered by BC. `Container` only |
| `getStats(): array` | Same numbers as an array (return shape is **not** covered by BC). Superseded by `stats()` |
| `getDependencyTree(string $className): array` | List the classes a given class depends on |
| `when(string\|array $concrete): ContextualBindingBuilder` | Define contextual bindings for specific classes (`needs()` accepts a type or a `$paramName`) |
| `offsetGet` / `offsetSet` / `offsetExists` / `offsetUnset` | `ArrayAccess`: `$c[Id::class]`, assignment, `isset()`, `unset()` |

## Static methods

```php
// Quick instantiation without container setup
$instance = Container::create(YourClass::class);

// Load compiled constructor plans from a cache file
$plans = Container::loadCompiledCache(__DIR__ . '/cache/container.php');
$container = new Container($bindings, [], $plans);
```

## The constructor

```php
public function __construct(
    array $bindings = [],        // abstract => concrete map
    array $instancesToExtend = [],  // id => list of extension closures
    array $compiledPlans = [],   // from writeCompiledCache() / loadCompiledCache()
)
```

All three parameters are optional and part of the stable API — their names, order,
types, and defaults do not change within a major version. Named arguments are safe.

## `Container` is final

`Container` cannot be subclassed. Extend behaviour by composition instead of
inheritance: type-hint `ContainerInterface` and wrap.

```php
final class LoggingContainer implements ContainerInterface
{
    public function __construct(
        private ContainerInterface $inner,
        private LoggerInterface $logger,
    ) {}

    public function get(string $id): mixed
    {
        $this->logger->debug('resolving', ['id' => $id]);

        return $this->inner->get($id);
    }

    // delegate the rest to $this->inner
}
```

For per-service changes you usually do not need a wrapper at all — prefer
[`extend()`](services.md) to decorate a single binding, or
[`afterResolving()`](resolution.md) to run code after an id resolves.

## Attributes

| Attribute | Target | Description |
|-----------|--------|-------------|
| `#[Inject(class-string)]` | parameter | Inject a specific implementation |
| `#[Singleton]` | class | Instantiate once and reuse |
| `#[Factory]` | class | Create a fresh instance every time |
| `#[Lazy]` | class | Defer construction until first use (PHP 8.4+; eager on 8.3) |

See [attributes](attributes.md) for examples.
