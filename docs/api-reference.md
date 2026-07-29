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
| `FullContainerInterface` | the same, plus everything 1.x could not add to it |
| `ContextualBindingBuilder` | returned by `when()` |
| `CompilationReport`, `CompilationSkipReason` | returned by `compileReport()` |
| `Attribute\Inject`, `Attribute\Singleton`, `Attribute\Factory`, `Attribute\Lazy` | the PHP 8 attributes |
| `Exception\ContainerException` | |
| `Exception\CircularDependencyException` | |
| `Exception\DependencyNotFoundException` | |
| `Exception\DependencyInvalidArgumentException` | |

For the exceptions, the **class and its PSR-11 interface** are stable. Exception
*messages* are not — do not parse or assert on them.

**`@internal` — not covered.** `AliasRegistry`, `BindingResolver`,
`DefinitionLoader`, `DependencyCacheManager`, `DependencyResolver`,
`DependencyTreeAnalyzer`, `FactoryManager`, `FuzzyMatcher`, `InstanceRegistry`,
`PlanRegistry`, and `TagRegistry` are implementation details of `Container`. They may change
signature, behaviour, or disappear entirely in **any** release, including a
patch. Do not import them.

## What the interface guarantees

Almost every instance method below is declared on `ContainerInterface`; the
eleven that are not are marked `FullContainerInterface`, the additive interface
that carries them. Type-hint whichever of the two you need.

`ContainerInterface` also extends `ArrayAccess`, so `$c[Id::class]`, `isset()`,
assignment, and `unset()` are part of the contract rather than a concrete-class
convenience.

Eleven methods are **not** on `ContainerInterface` and never will be within
1.x, which promises nothing will be added to it: `stats()`, `createScope()`,
`provides()`, `lazy()`, `load()`, `loadFile()`, `taggedByKey()`,
`taggedKeys()`, `writeCompiledFactories()`, `useCompiledFactories()` and
`compileReport()`.

They are on **`FullContainerInterface`**, which extends `ContainerInterface` and
adds exactly those eleven. Adding a new interface breaks nothing — nothing is
added to `ContainerInterface`, so the 1.x promise holds literally and no
existing implementor of it is affected. Type-hint it when you want the whole
surface without coupling to the `final` class:

```php
public function __construct(
    private FullContainerInterface $container,
) {
}
```

`createScope()` is typed `static` there, so a scope of a full container is a
full container, and a decorator's scope is a decorator.

At **2.0** these methods move onto `ContainerInterface` itself and
`FullContainerInterface` stays as a deprecated alias, so code written against it
now does not migrate twice.

What remains on `Container` alone is what an instance contract cannot express:
the constructor, and the static methods `create()`, `loadCompiledCache()`,
`loadCompiledFactories()` and `resetStaticCaches()` — there is no instance that
owns that state to ask, and so nothing for a decorator to forward.

Two caveats. `getStats()` is on the interface, but the **shape of the array it
returns is not covered by backward compatibility** — treat it as debug output,
or use `stats()`, whose shape *is* covered. And the memory figure in both is the
whole PHP **process**, not the container; every other field is container-scoped,
so it is the one number that does not answer "what does this container hold".
See [introspection](services.md#introspection).

## Container methods

| Method | Description |
|--------|-------------|
| `get(string $id): mixed` | Retrieve or create a service |
| `getOrFail(string $id): mixed` | Like `get()`, but throws when the id resolves to `null` |
| `make(string $className, array $parameters = []): object` | Resolve a class to a typed, non-null instance; `$parameters` override constructor args by name |
| `has(string $id): bool` | PSR-11: whether `get()` will resolve the id — includes autowirable classes |
| `afterResolving(string $id, Closure $callback): void` | Run a callback after the id is resolved (`$instance`, `$container`) |
| `bound(string $id): bool` | Whether the id was explicitly registered — a binding or a stored instance (alias-aware) |
| `provides(string $id): bool` | Whether this container or an ancestor already owns the id — a binding, a stored instance, or a resolved singleton. `FullContainerInterface` |
| `bindIf(string $abstract, string\|callable\|object $concrete): void` | Bind only if not already bound |
| `singletonIf(string $abstract, string\|callable\|object\|null $concrete = null): void` | Singleton-bind only if not already bound |
| `bind(string $abstract, string\|callable\|object $concrete): void` | Register a binding after construction |
| `singleton(string $abstract, string\|callable\|object\|null $concrete = null): void` | Register a binding resolved once and reused |
| `lazy(string $abstract, string\|callable\|null $concrete = null): void` | Register a binding whose construction is deferred to first use. `FullContainerInterface` — see [bindings](bindings.md#deferred-registration) |
| `load(array $definitions): void` | Register services from a definitions array. `FullContainerInterface` — see [definitions](definitions.md) |
| `loadFile(string $file): void` | Load definitions from a `.php` file returning an array, or a `.json` file. `FullContainerInterface` |
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
| `writeCompiledCache(array $classNames, string $file): void` | Compile plans and write them to a PHP cache file, fingerprinted against the files they came from — see [staleness](performance.md#staleness). `Container` takes an optional third `?string $buildStamp` |
| `writeCompiledFactories(array $classNames, string $file, ?string $buildStamp = null): array` | Generate `new` expressions for statically-decidable classes; returns those compiled. `FullContainerInterface` |
| `compileReport(array $classNames): CompilationReport` | What the generator makes of these classes, and why it refuses the rest. `FullContainerInterface` — see [performance](performance.md#asking-why) |
| `useCompiledFactories(array $factories): void` | Use generated factories as a fast path. `FullContainerInterface` |
| `alias(string $alias, string $id): void` | Create an alias for a service |
| `tag(string\|array $ids, string $tag): void` | Group service ids under a tag (accumulates, dedupes); a map gives entries keys |
| `tagged(string $tag): iterable` | Lazily resolve all services under a tag, in insertion order; keyed entries under their key |
| `taggedByKey(string $tag, string $key): mixed` | Resolve the one entry under `$key`; throws naming the known keys if there is none. `FullContainerInterface` — see [tags](bindings.md#keyed-tags) |
| `taggedKeys(string $tag): array` | The keys a tag can be asked for, in insertion order. `FullContainerInterface` |
| `createScope(): static` | A child container inheriting this one's registration without copying it. `FullContainerInterface` — see [scopes](scopes.md) |
| `stats(): ContainerStats` | Container statistics as a readonly object; shape is covered by BC. Its `memoryUsageBytes` is **process** memory, not this container's — see [introspection](services.md#introspection). `FullContainerInterface` |
| `getStats(): array` | Same numbers as an array (return shape is **not** covered by BC). Superseded by `stats()` |
| `getDependencyTree(string $className): array` | List the classes a given class depends on |
| `when(string\|array $concrete): ContextualBindingBuilder` | Define contextual bindings for specific classes (`needs()` accepts a type or a `$paramName`) |
| `offsetGet` / `offsetSet` / `offsetExists` / `offsetUnset` | `ArrayAccess`: `$c[Id::class]`, assignment, `isset()`, `unset()` |

## Static methods

```php
// Quick instantiation without container setup
$instance = Container::create(YourClass::class);

// Clear the reflection caches shared by every container in the process.
// See performance.md#process-global-caches for what is and is not cleared
Container::resetStaticCaches();

// Load compiled constructor plans from a cache file. Entries whose class file
// changed since the cache was written are dropped and fall back to reflection
$plans = Container::loadCompiledCache(__DIR__ . '/cache/container.php');
$container = new Container($bindings, [], $plans);

// Same, for generated `new` expressions
$container->useCompiledFactories(
    Container::loadCompiledFactories(__DIR__ . '/cache/factories.php'),
);
```

Both take an optional second argument, a build stamp: pass the same value used
when the file was written and the whole file is validated in one comparison
instead of one `stat` per class. See [staleness](performance.md#staleness).

## The constructor

```php
public function __construct(
    array $bindings = [],        // abstract => concrete map
    array $instancesToExtend = [],  // id => list of extension closures
    array $compiledPlans = [],   // from writeCompiledCache() / loadCompiledCache()
    ?PlanCache $planCache = null, // one plan cache shared with unrelated containers
)
```

`PlanCache` shares reflection output — constructor plans — between containers
that have no parent/scope relationship, and nothing else: bindings, contextual
bindings, instances and singletons stay private to each container. See
[performance](performance.md#one-plan-cache-for-several-containers).

All four parameters are optional and part of the stable API — their names, order,
types, and defaults do not change within a major version. Named arguments are safe.

## `Container` is final

`Container` cannot be subclassed. Extend behaviour by composition instead of
inheritance: type-hint an interface and wrap.

Implement `FullContainerInterface` rather than `ContainerInterface` when the
wrapper is meant to stand in for a container everywhere. It costs more
forwarders and buys the compiler checking them: a method the wrapper forgets is
a compile error, not a method that silently goes missing at runtime.

```php
final class LoggingContainer implements FullContainerInterface
{
    public function __construct(
        private FullContainerInterface $inner,
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
