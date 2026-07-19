# Gacela Container

<p align="center">
  <a href="https://github.com/gacela-project/container/actions">
    <img src="https://github.com/gacela-project/container/workflows/CI/badge.svg" alt="GitHub Build Status">
  </a>
  <a href="https://scrutinizer-ci.com/g/gacela-project/container/?branch=main">
    <img src="https://scrutinizer-ci.com/g/gacela-project/container/badges/quality-score.png?b=main" alt="Scrutinizer Code Quality">
  </a>
  <a href="https://scrutinizer-ci.com/g/gacela-project/container/?branch=main">
    <img src="https://scrutinizer-ci.com/g/gacela-project/container/badges/coverage.png?b=main" alt="Scrutinizer Code Coverage">
  </a>
  <a href="https://shepherd.dev/github/gacela-project/container">
    <img src="https://shepherd.dev/github/gacela-project/container/coverage.svg" alt="Psalm Type-coverage Status">
  </a>
  <a href="https://github.com/gacela-project/container/blob/master/LICENSE">
    <img src="https://img.shields.io/badge/License-MIT-green.svg" alt="MIT Software License">
  </a>
</p>

A minimalistic, PSR-11 compliant dependency injection container with automatic constructor injection and zero configuration.

## Features

- 🚀 **Zero Configuration**: Automatic constructor injection without verbose setup
- 🔄 **Circular Dependency Detection**: Clear error messages when dependencies form a loop
- 📦 **PSR-11 Compliant**: Standard container interface for interoperability
- ⚡ **Performance Optimized**: Built-in caching, warmup, and a compiled cache that skips reflection
- 🧩 **Fluent Registration**: Register bindings after construction with `bind()` and `singleton()`
- 🎁 **Typed Resolution**: `make()` returns a typed instance; `getOrFail()` never returns `null`
- 🔍 **Introspection**: Debug and inspect container state easily
- 🎯 **Type Safe**: Requires type hints for reliable dependency resolution
- 🏷️ **PHP 8 Attributes**: Declarative configuration with `#[Inject]`, `#[Singleton]`, and `#[Factory]`

## Installation

```bash
composer require gacela-project/container
```

Requires PHP >= 8.1.

## Hello World

```php
use Gacela\Container\Container;

class Greeter {
    public function __construct(private Clock $clock) {}

    public function greet(): string {
        return 'Hello World at ' . $this->clock->now();
    }
}

class Clock {
    public function now(): string {
        return date('H:i:s');
    }
}

// Zero configuration — dependencies are auto-wired from type hints
$container = new Container();
$greeter = $container->make(Greeter::class);

echo $greeter->greet();
```

Need interfaces, singletons, attributes, or a compiled cache? See the docs below.

## Documentation

| Guide | What's inside |
|-------|---------------|
| [Getting Started](docs/getting-started.md) | Installation, basic usage, how resolution works |
| [Bindings & Registration](docs/bindings.md) | Constructor bindings, `bind()`/`singleton()`, contextual bindings, aliasing |
| [Resolving Services](docs/resolution.md) | `get()`, `make()`, `getOrFail()`, `resolve()`, transient vs. shared |
| [PHP 8 Attributes](docs/attributes.md) | `#[Inject]`, `#[Singleton]`, `#[Factory]` |
| [Managing Services](docs/services.md) | Factories, extending, protecting closures, introspection |
| [Performance & Compilation](docs/performance.md) | `warmUp()`, compiled container cache |
| [Error Handling](docs/error-handling.md) | Error messages and what they mean |
| [Best Practices](docs/best-practices.md) | Recommended patterns |
| [API Reference](docs/api-reference.md) | Full method, static, and attribute reference |

## Real-World Example

See how it's used in the [Gacela Framework](https://github.com/gacela-project/gacela/blob/main/src/Framework/ClassResolver/AbstractClassResolver.php#L142).

## Testing

```bash
composer test          # Run tests
composer quality       # Run static analysis
composer test-coverage # Generate coverage report
```

## License

MIT License. See [LICENSE](LICENSE) file for details.
