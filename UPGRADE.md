# Upgrade Guide

## 1.x → 2.0

Two changes can require action, and both only affect code that **implements**
a container interface. Callers are unaffected: every existing call keeps working.

---

### 1. `ContainerInterface` declares the whole surface

1.x promised nothing would be added to `ContainerInterface`, which is why most of
the 1.2–1.5 feature set was reachable only through the concrete `final class
Container` — and why 1.5 introduced `FullContainerInterface` as an additive
answer. 2.0 merges them: `createScope()`, `provides()`, `stats()`, `lazy()`,
`load()`, `loadFile()`, `taggedByKey()`, `taggedKeys()`, `dependencyGraph()`,
`compileReport()`, `writeCompiledFactories()`, `useCompiledFactories()`,
`validate()` and `withSelfReference()` are all on `ContainerInterface` now.

**If you only ever type-hint or call a container, there is nothing to do.**

**If you implement `ContainerInterface` yourself** — a test double, most likely —
it must now declare those methods. The compiler will tell you exactly which.
Three options:

```php
// 1. Wrap a real container and forward. The interface then enforces
//    completeness for you: a method added upstream fails compilation here
//    rather than silently going missing.
final class MyContainer implements ContainerInterface
{
    public function __construct(private Container $inner) {}

    public function get(string $id): mixed { return $this->inner->get($id); }
    // ...
}

// 2. In tests, use the real container. It has no I/O and no global state.
$container = new Container();

// 3. Type-hint PSR-11 instead, if all you need is get()/has().
use Psr\Container\ContainerInterface as PsrContainer;
```

`FullContainerInterface` still exists and still works — it is now an empty,
**deprecated** alias extending `ContainerInterface`, so a 1.5 type-hint keeps
compiling and keeps accepting a `Container`. Switch to `ContainerInterface` when
convenient; the alias goes at 3.0.

---

### 2. `load()` and `loadFile()` return the ids they registered

Were `void`:

```php
$ids = $container->loadFile('config/services.php');
// ['App\Mailer', 'db.dsn', 'repository']
```

This is the only reliable answer to "what did this source register" — reading
the ids back off the container afterwards catches `bind()` and `set()` entries
and **misses aliases**, which live in a third registry.

**Nothing breaks for a caller**: ignoring a return value is always valid. Only an
implementor of `ContainerInterface` has to change the signature.

The optional listener added in 1.5 still works, for a consumer that wants the ids
one at a time as they are registered rather than as a list:

```php
$container->loadFile($file, static fn (string $id) => $events->dispatch(new Registered($id)));
```

---

## 0.10.0 → 1.0.0

Four changes can require action. Most applications need only the PHP bump.

From 1.0 onward, changes like these require a new major version — see
[the backward compatibility policy](docs/backward-compatibility.md).

---

### 1. PHP 8.3 is now the minimum

Was `>=8.1`. PHP 8.1 and 8.2 are past end of security support, and raising the
floor is itself a breaking change — so it had to happen before the freeze rather
than during 1.x.

```diff
 "require": {
-    "php": ">=8.1"
+    "php": ">=8.3"
 }
```

**Action:** upgrade to PHP 8.3 or newer. 8.3, 8.4, and 8.5 are covered by CI.

---

### 2. `Container` is `final`

`final` was removed in 0.4.0 to allow decorating by subclassing. That is no
longer supported: `Container` has no `protected` members, so a subclass could
only ever reach the public API — which `ContainerInterface` already gives you.

**Action:** replace inheritance with composition.

```php
// Before
final class MyContainer extends Container
{
    public function get(string $id): mixed
    {
        $this->logger->debug('resolving', ['id' => $id]);

        return parent::get($id);
    }
}

// After
final class MyContainer implements ContainerInterface
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

    // delegate the remaining methods to $this->inner
}
```

For a single service rather than the whole container, you probably do not need a
wrapper at all — `extend()` decorates one binding, and `afterResolving()` runs a
callback after an id resolves.

---

### 3. `has()` now reports what `get()` will actually resolve

`has()` previously consulted only the instance registry, so under autowiring it
returned `false` for ids that `get()` resolves without complaint — which breaks
the standard PSR-11 pattern.

```php
$container = new Container();

// Before
$container->has(Greeter::class);   // false
$container->get(Greeter::class);   // ...returns a Greeter anyway

// After
$container->has(Greeter::class);   // true
```

`has()` is now `true` for stored instances, bindings, **and any instantiable
class**. `isset($container[$id])` follows it.

**`bound()` is unchanged** and still answers the narrower question — was this id
explicitly registered?

| | `has()` | `bound()` |
|---|---|---|
| stored instance | ✅ | ✅ |
| binding | ✅ | ✅ |
| autowirable class | ✅ | ❌ |
| unbound interface | ❌ | ❌ |
| abstract class | ❌ | ❌ |

**Action:** if you used `has()` to mean "was this registered?", switch to
`bound()`. If you used it to guard a `get()` call, it now behaves correctly and
needs no change.

---

### 4. `ContainerInterface` gained methods and extends `ArrayAccess`

Only affects you if you **implement** `ContainerInterface` yourself. Consumers
that merely type-hint it need no change.

Added: `when()`, `compile()`, `writeCompiledCache()`, `getStats()`, plus the four
`ArrayAccess` methods (`offsetGet`, `offsetSet`, `offsetExists`, `offsetUnset`).

This was deliberate: adding a method to a published interface breaks every
implementer, so the interface had to reach its final shape before 1.0. It will
not gain further methods during 1.x.

**Action:** implement the new methods, or delegate them to a wrapped
`Container`.

---

### Also worth knowing

- **`@internal` classes.** The nine resolver internals (`DependencyResolver`,
  `AliasRegistry`, `FactoryManager`, …) are now explicitly outside the BC
  promise. If you imported one, move off it — it may change in any release.
- **`getStats()` return shape** is explicitly excluded from BC. Do not build
  logic on it.
- **`psr/container`** is now constrained to `^1.1 || ^2.0` rather than the
  unbounded `>=1.1`. Both are tested.
- **Compiled caches must be regenerated.** The file written by
  `writeCompiledCache()` is tied to the version that produced it.

### Nothing to do for

Binding registration, contextual bindings, tags, attributes, `make()`,
`getOrFail()`, `resolve()`, runtime parameters, and `afterResolving()` are all
unchanged from 0.10.0.
