# Scopes

[← Back to index](../README.md#documentation)

A scope is a child container: it resolves everything its parent resolves, plus
whatever is registered on it directly.

```php
$app = new Container([...]);            // registered once
$request = $app->createScope();         // cheap
$request->set('key', $perRequestThing); // local
$request->get(SomeService::class);      // falls through to $app
```

## What it is for

Two problems that look unrelated turn out to be the same missing primitive.

**Sharing configuration across many containers.** Building a second container
otherwise means re-registering everything — bindings, factories, protected
services, aliases, contextual bindings. When the only difference between two
containers is a handful of entries, that is a lot of duplicated work to vary
almost nothing. Service ids that are module-local strings (`'key'`,
`'some.service'`) rule out the obvious alternative of one flat container, since
two modules using the same key would silently collide.

**A per-request scope in a long-running runtime** (Swoole, RoadRunner,
FrankenPHP), where request-lifetime services must be discarded between requests
while app-lifetime ones are resolved once at boot.

A scope answers both: registration lives in one place, and everything a scope
touched goes away when the scope does.

## Creating one is cheap

`createScope()` re-registers nothing. The scope starts empty and looks upward on
a miss, so the cost is the same whether the parent holds three bindings or three
thousand — cheap enough to do per request, or per module. Scopes nest: a scope
can create a scope.

Two maps are copied rather than looked up through the chain — contextual
bindings and generated factories — and copy-on-write makes both free until the
scope writes to them. See [what does not fall through](#what-does-not-fall-through).

## Resolution order

For `$scope->get($id)`:

1. an instance stored on the scope itself
2. a binding registered on the scope itself
3. otherwise, if any ancestor already owns something for `$id`, **that ancestor
   resolves it** and hands over its own instance
4. otherwise the scope autowires it locally

Steps 2–4 apply to nested constructor dependencies too, not only to top-level
`get()` calls. Step 1 does not: stored instances are a `get()` concern, and a
constructor dependency is resolved from bindings, never from `set()` — that is
already true of a plain container and is unchanged here.

## Shadowing

Anything registered on a scope — `set()`, `bind()`, `singleton()`, `alias()`,
`tag()`, `when()` — applies to that scope alone and never mutates the parent.

```php
$app = new Container();
$app->bind(RepositoryInterface::class, DatabaseRepository::class);

$scope = $app->createScope();
$scope->bind(RepositoryInterface::class, InMemoryRepository::class);

$scope->get(RepositoryInterface::class); // InMemoryRepository
$app->get(RepositoryInterface::class);   // DatabaseRepository, untouched
```

A scope may even shadow an id the parent has already frozen. Freezing protects
the parent's copy; the scope's is a different one.

## Ownership decides lifetime

A scope delegates an id to the nearest ancestor that **owns** it. An ancestor
owns an id when it has a binding for it, holds an instance for it, or has
already resolved it as a singleton — exactly what
[`provides()`](#provides) answers.

So the rule is about ownership, not about resolution in general:

- **Bound on the parent** → the parent owns it from the start, and every scope
  shares whatever it produces. For a `singleton()` that means one instance for
  the whole chain; for a plain `bind()`, a fresh one per call, built by the
  parent.
- **Autowired, parent resolved it first** → a `#[Singleton]` class the parent
  has already built is owned by the parent, so scopes share it. A non-singleton
  is not owned by anybody, so each scope builds its own — which is what
  "transient" means.
- **Autowired, a scope resolved it first** → a `#[Singleton]` class belongs to
  that scope, and is released with it. Sibling scopes each get their own.

```php
$app = new Container();
$shared = $app->get(SomeSingleton::class);       // the parent now owns it

$app->createScope()->get(SomeSingleton::class);  // === $shared
```

That last case makes an autowired singleton's identity depend on your boot
sequence. If you want one shared by every scope regardless, **register it on the
parent**:

```php
$app->singleton(SomeSingleton::class);
```

Registering makes the parent own it immediately, so resolution order stops
mattering. The flip side is the one to watch for a request scope: anything
registered on the parent lives as long as the parent, so register there only
what is genuinely app-lifetime.

## Disposal is dropping the reference

A parent holds no reference back to its scopes. Drop the scope and every
instance it owned is released with it; everything the parent owns stays put.
Nothing needs to be closed or reset. (Constructor plans are the exception, and
deliberately so — see [compiled plans](#compiled-plans-are-shared).)

```php
foreach ($server->requests() as $request) {
    $scope = $app->createScope();
    $scope->set(Request::class, $request);

    $handler->handle($scope);
    // $scope goes out of range here, and takes its instances with it
}
```

Closures a `bind()` or `set()` registered on the parent receive **the parent**,
not the scope. That is deliberate: a service shared by every scope must not
capture the state of whichever one happened to trigger it. Contextual-binding
closures are the exception — they are copied into the scope, so they receive the
scope.

## What does not fall through

**`remove()`** only forgets what the scope itself stored — removing an inherited
id would mutate the ancestor holding it. Removing a shadowing entry makes the id
resolve through the parent again.

**`extend()`** throws a `ContainerException` when an ancestor owns the id, for
the same reason — a binding counts, not only a stored instance. Extending an id
nobody owns yet still schedules on the scope, as it always has. To decorate a
parent's service for one scope only, shadow it:

```php
$scope->set('logger', static fn () => new LoggerDecorator($app->get('logger')));
```

**`afterResolving()`** callbacks fire for the resolutions their own container
performs. A scope resolving an id its parent registered *is* a resolution the
parent performs, so callbacks registered up there still fire — which is the
normal case, since hooks target registered services. One the scope autowires by
itself is not, so they do not.

**Contextual bindings** and the map installed by **`useCompiledFactories()`** are
copied into a scope when it is created, rather than looked up through the chain.
Both are consulted on the resolution path — contextual bindings once per nested
parameter — so a chain walk would tax every miss to serve a map that is
configuration, fixed before the scopes that read it exist. Define them before
creating scopes; a `when()` or `useCompiledFactories()` call on the parent
afterwards is not visible to scopes that already exist.

Bindings, instances and aliases have no such caveat: those are looked up live,
so registering on the parent later still reaches existing scopes.

**`bindIf()` / `singletonIf()`** ask `bound()`, which sees the whole chain. On a
scope they are therefore no-ops when any ancestor already bound the id. Use
`bind()` when a scope must override regardless.

## Introspection reports the whole chain

Because a scope can resolve what its ancestors registered, it reports it too:
`has()`, `bound()`, `provides()`, `getBindings()`, `getRegisteredServices()`,
`tagged()`, `isFactory()`, `isFrozen()`, `getDependencyTree()`, and therefore
`stats()`. A shadowed id is listed once. `getRegisteredServices()` and
`tagged()` list inherited entries first; `getBindings()` lists the scope's own
first. A [keyed tag](bindings.md#keyed-tags) entry is the one part of a tag a
scope can shadow: re-registering the same key on the scope replaces it there and
leaves the parent's alone, the way a binding does.

`isFactory()` and `isFrozen()` answer for whichever container owns the instance,
so a scope shadowing an id reports its own copy — unfrozen however long the
ancestor's has been read.

`stats()->cachedDependencies` is the exception: it counts what this container's
own cache holds, nothing more.

## `provides()`

```php
public function provides(string $id): bool
```

True when this container or an ancestor already owns something for `$id`: a
binding, a stored instance, or a singleton it has resolved or been told to treat
as one. It is the predicate a scope uses at step 3 above, and it sits between
the two you already have:

| | true when |
|---|---|
| `bound($id)` | something was explicitly registered — a binding or a stored instance |
| `provides($id)` | that, plus a singleton no binding named: one already resolved, or the concrete class of a `singleton($abstract, $concrete)` |
| `has($id)` | that, plus anything merely autowirable |

## Compiled plans are shared

Along the parent axis, automatically; between unrelated containers, by handing
them one [`PlanCache`](performance.md#one-plan-cache-for-several-containers).

A constructor plan is reflection output keyed by class name, so whichever
container in a chain builds it first, all of them use the result — live, and in
both directions. A scope created before the parent warms up still sees the
parent's later plans, and plans a scope discovers outlive it. `compile()` and
`writeCompiledCache()` on any container in a chain therefore export the chain's
plans.

One consequence worth knowing: a plan stores each constructor parameter's
default value, so a `new` in an initializer is captured once and reused. Within
a single container that was already true; sharing the plans extends it across
the chain, which makes a *mutable* default object visible to every scope. Do not
use one as a default if you expect per-instance state — that is fragile
regardless of scopes.

Singleton instances are deliberately *not* shared this way. Those are what a
scope exists to keep separate.

## Backward compatibility

`createScope()` and `provides()` live on `Container`, not on
`ContainerInterface` — 1.x promises no method will be added to that interface.
Both move onto it in 2.0. This is the same carve-out
[`stats()`](services.md#introspection) got in 1.2.0.

## Related

- [Bindings & registration](bindings.md)
- [Managing services](services.md)
- [Backward compatibility](backward-compatibility.md)
