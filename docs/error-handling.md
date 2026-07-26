# Error Handling

[← Back to index](../README.md#documentation)

Every exception implements a PSR-11 interface, so you can catch across the whole
family:

| Exception | PSR-11 interface | Extends |
|---|---|---|
| `DependencyNotFoundException` | `NotFoundExceptionInterface` | `RuntimeException` |
| `CircularDependencyException` | `ContainerExceptionInterface` | `RuntimeException` |
| `DependencyInvalidArgumentException` | `ContainerExceptionInterface` | `InvalidArgumentException` |
| `ContainerException` | `ContainerExceptionInterface` | `Exception` |

> **Do not parse these messages.** The exception classes and their PSR-11
> interfaces are covered by [backward compatibility](backward-compatibility.md);
> the message text explicitly is not, so it stays free to improve. Catch the
> class, never match on the string.

## `DependencyNotFoundException`

### No concrete class for an interface

Thrown when an interface is needed as a dependency and nothing is bound to it.
The container fuzzy-matches your existing bindings and suggests near misses,
which usually means a typo:

```
No concrete class was found that implements:
"App\LogerInterface"
Did you forget to bind this interface to a concrete class?

Did you mean one of these?
  - App\LoggerInterface

You might find some help here: https://gacela-project.com/docs/bootstrap/#bindings
```

**Fix:** bind the interface — `$container->bind(LoggerInterface::class, FileLogger::class)`.

Note the asymmetry: resolving an unbound interface *directly* with `get()`
returns `null`, while resolving it as somebody's constructor dependency throws.
Use `getOrFail()` for the strict behaviour at the top level.

## `CircularDependencyException`

```
Circular dependency detected: ClassA -> ClassB -> ClassC -> ClassA

This happens when classes depend on each other in a loop.
Consider using setter injection or the factory pattern to break the cycle.
```

The chain is the resolution path in order, so you can see which edge to break.

**Fix:** break the cycle — extract the shared piece into a third class, or make
one side [`#[Lazy]`](attributes.md) so it is not constructed while the other is
still being built.

## `DependencyInvalidArgumentException`

### Missing type hint

```
No type hint found for parameter '$logger'.
Type hints are required for dependency injection to work properly.

Add a type hint to the parameter, for example:
  public function __construct(YourClass $logger) { ... }
```

### Unresolvable scalar

```
Unable to resolve parameter of type 'string' in 'UserService'.
Scalar types (string, int, float, bool, array) cannot be auto-resolved.

Provide a default value for the parameter:
  public function __construct(string $param = 'default') { ... }
```

**Fix:** give the parameter a default, or bind it by name with
`when(UserService::class)->needs('$param')->give(...)`. See the
[cookbook](cookbook.md).

## `ContainerException`

Four situations, all about the lifecycle of an already-registered service.

### Frozen: cannot be overridden

```
The instance 'db' is frozen and cannot be overridden.
Services become frozen after being accessed via get() to ensure consistency.

Call remove('db') before setting a new value, or avoid accessing it before replacement.
```

### Frozen: cannot be extended

```
The instance 'db' is frozen and cannot be extended.
Services become frozen after being accessed via get() to ensure consistency.

Extend the service before accessing it, or use remove() to unfreeze it first.
```

A service freezes once it has been read. Register and decorate everything during
bootstrap, before the first `get()`.

### Protected: cannot be extended

```
The instance 'config' is protected and cannot be extended.
Protected closures are treated as values, not as service factories.

Remove the protect() wrapper if you need to extend this service.
```

`protect()` marks a closure as a *value* to hand back untouched rather than a
factory to invoke, so extending it is a contradiction.

### Not extendable

```
The passed instance is not extendable.
Only objects, arrays, and callables can be extended.

Ensure the service is one of these types before calling extend().
```

Scalars cannot be decorated.

## Related

- [Cookbook](cookbook.md) — recipes for the fixes above
- [Best practices](best-practices.md) — how to avoid these
- [Backward compatibility](backward-compatibility.md) — why not to parse messages
