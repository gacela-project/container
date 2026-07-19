# Error Handling

[← Back to index](../README.md#documentation)

Error messages include context and suggestions.

## Missing type hint

```
No type hint found for parameter '$logger'.
Type hints are required for dependency injection to work properly.

Add a type hint to the parameter, for example:
  public function __construct(YourClass $logger) { ... }
```

## Circular dependency

```
Circular dependency detected: ClassA -> ClassB -> ClassC -> ClassA

This happens when classes depend on each other in a loop.
Consider using setter injection or the factory pattern to break the cycle.
```

## Unresolvable scalar

```
Unable to resolve parameter of type 'string' in 'UserService'.
Scalar types (string, int, float, bool, array) cannot be auto-resolved.

Provide a default value for the parameter:
  public function __construct(string $param = 'default') { ... }
```

## Service not found (with suggestions)

```
No concrete class was found that implements:
"App\LogerInterface"
Did you forget to bind this interface to a concrete class?

Did you mean one of these?
  - App\LoggerInterface
  - App\Service\LoggerInterface

You might find some help here: https://gacela-project.com/docs/bootstrap/#bindings
```

## Related

- [Best practices](best-practices.md) — how to avoid these
