# Gacela Container

<p align="center">
  <a href="https://github.com/gacela-project/container/actions">
    <img src="https://github.com/gacela-project/container/workflows/CI/badge.svg" alt="GitHub Build Status">
  </a>
  <a href="https://packagist.org/packages/gacela-project/container">
    <img src="https://img.shields.io/packagist/v/gacela-project/container.svg" alt="Latest Version">
  </a>
  <a href="https://packagist.org/packages/gacela-project/container">
    <img src="https://img.shields.io/packagist/php-v/gacela-project/container.svg" alt="PHP Version Require">
  </a>
  <a href="https://shepherd.dev/github/gacela-project/container">
    <img src="https://shepherd.dev/github/gacela-project/container/coverage.svg" alt="Psalm Type-coverage Status">
  </a>
  <a href="https://github.com/gacela-project/container/blob/main/LICENSE">
    <img src="https://img.shields.io/badge/License-MIT-green.svg" alt="MIT Software License">
  </a>
</p>

A minimalistic, PSR-11 compliant dependency injection container with automatic constructor injection and zero configuration.

## Features

- 🚀 **Zero Configuration**: Automatic constructor injection without verbose setup
- 🔄 **Circular Dependency Detection**: Clear error messages when dependencies form a loop
- 📦 **PSR-11 Compliant**: Standard container interface for interoperability
- ⚡ **Performance Optimized**: Built-in caching, warmup, and a compiled cache that skips reflection
- 🧩 **Fluent Registration**: Register bindings after construction with `bind()`, `singleton()` and `lazy()`
- 🌱 **Scopes**: Child containers that inherit registration without copying it, for per-request lifetimes
- 🎁 **Typed Resolution**: `make()` returns a typed instance; `getOrFail()` never returns `null`
- 🔍 **Introspection**: Debug and inspect container state easily
- 🎯 **Type Safe**: Requires type hints for reliable dependency resolution
- 🏷️ **PHP 8 Attributes**: Declarative configuration with `#[Inject]`, `#[Singleton]`, `#[Factory]`, and `#[Lazy]`

## Installation

```bash
composer require gacela-project/container
```

Requires PHP >= 8.3.

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

## How it compares

|  | Gacela | [Pimple](https://github.com/silexphp/Pimple) | [Laravel](https://github.com/illuminate/container) | [PHP-DI](https://php-di.org) | [Symfony](https://symfony.com/doc/current/service_container.html) |
|---|:---:|:---:|:---:|:---:|:---:|
| PSR-11 | ✅ | wrapper | ✅ | ✅ | ✅ |
| Autowiring with zero config | ✅ | ❌ | ✅ | ✅ | needs config |
| Framework-independent | ✅ | ✅ | pulls `illuminate/contracts` | ✅ | ✅ |
| Lifetimes as attributes | `#[Singleton]` `#[Factory]` `#[Lazy]` | ❌ | ❌ | `#[Inject]` only | `#[Autoconfigure]` |
| Lazy services | ✅ native lazy objects, attribute or `lazy()`, no proxy class | ❌ | ❌ | ✅ via proxy library | ✅ |
| Compiled resolution | ✅ plans + generated factories | ❌ | ❌ | ✅ | ✅ |
| Child/scope containers | ✅ inherits without copying | ❌ | ❌ | ❌ | ❌ |
| Contextual bindings | ✅ | ❌ | ✅ | definitions | ✅ |
| Tags | ✅ | ❌ | ✅ | ❌ | ✅ |
| Invoke any callable | ✅ `resolve()` | ❌ | ✅ `call()` | ✅ `call()` | ❌ |
| Circular dependencies | ✅ named exception + path | ❌ | ✅ | ✅ | ✅ at compile time |
| Introspection | ✅ `stats()`, dependency tree, typo hints | ❌ | ❌ | limited | ✅ via console |
| Array access | ✅ | ✅ | ✅ | ❌ | ❌ |
| Definitions as data | ✅ arrays, PHP + JSON files | ❌ | ❌ | ✅ | ✅ |
| YAML/XML definition files | ❌ parse it and `load()` the array | ❌ | ❌ | ✅ | ✅ |
| Compiler passes / extensions | ❌ | ❌ | ❌ | ❌ | ✅ |

**Use this if** you want Pimple's footprint with real autowiring, or Laravel's
container API without Laravel — plus lazy services, per-request scopes, and a
compiled cache that skips reflection entirely.

**Look elsewhere if** you need container definitions in YAML/XML, or
compiler-pass style extension points. Those are Symfony's territory by design.

## Documentation

| Guide | What's inside |
|-------|---------------|
| [Getting Started](docs/getting-started.md) | Installation, basic usage, how resolution works |
| [Bindings & Registration](docs/bindings.md) | Constructor bindings, `bind()`/`singleton()`, contextual bindings, aliasing |
| [Definitions as Data](docs/definitions.md) | `load()`/`loadFile()`: wiring from arrays, PHP and JSON files |
| [Resolving Services](docs/resolution.md) | `get()`, `make()`, `getOrFail()`, `resolve()`, transient vs. shared |
| [PHP 8 Attributes](docs/attributes.md) | `#[Inject]`, `#[Singleton]`, `#[Factory]` |
| [Managing Services](docs/services.md) | Factories, extending, protecting closures, introspection |
| [Scopes](docs/scopes.md) | Child containers: inherited registration, per-request lifetimes |
| [Performance & Compilation](docs/performance.md) | `warmUp()`, compiled container cache |
| [Cookbook](docs/cookbook.md) | Recipes: testing, config, plugins, decorating, debugging |
| [Error Handling](docs/error-handling.md) | Every exception, what causes it, how to fix it |
| [Best Practices](docs/best-practices.md) | Recommended patterns |
| [API Reference](docs/api-reference.md) | Full method, static, and attribute reference |
| [Backward Compatibility](docs/backward-compatibility.md) | What semver covers here, and what it does not |
| [Upgrade Guide](UPGRADE.md) | Migrating from 0.10.0 to 1.0.0 |

## Real-World Example

See how it's used in the [Gacela Framework](https://github.com/gacela-project/gacela/blob/main/src/Framework/ClassResolver/AbstractClassResolver.php#L161).

## Testing

```bash
composer test          # Run tests
composer quality       # Run static analysis
composer test-coverage # Generate coverage report
```

## License

MIT License. See [LICENSE](LICENSE) file for details.
