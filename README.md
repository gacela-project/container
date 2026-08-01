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
- ⚡ **Performance Optimized**: Warm resolution builds straight to `new`, plus warmup, a compiled cache that skips reflection, and one plan cache shared across sibling containers
- 🧩 **Fluent Registration**: Register bindings after construction with `bind()`, `singleton()` and `lazy()`
- 🌱 **Scopes**: Child containers that inherit registration without copying it, for per-request lifetimes
- 🎁 **Typed Resolution**: `make()` returns a typed instance; `getOrFail()` never returns `null`
- 🧵 **Tags**: Group services under a tag and resolve them lazily, as a list or as a keyed map
- 🧭 **Contextual Bindings**: `when()` scopes a dependency — or a scalar — to the classes that ask for it
- 📄 **Definitions as Data**: Ship and override wiring as arrays, PHP, JSON or YAML files with `load()`/`loadFile()`
- 🪝 **Resolution Hooks**: `afterResolving()` callbacks run once an id is built
- 🔍 **Introspection**: Debug and inspect container state easily
- 🎯 **Type Safe**: Requires type hints for reliable dependency resolution
- 🏷️ **PHP 8 Attributes**: Declarative configuration with `#[Inject]`, `#[Singleton]`, `#[Factory]` and `#[Lazy]` — on parameters, properties and setters, and subclassable under your own namespace
- ✅ **Build-time Validation**: `validate()` proves a set of classes resolves *without resolving them*, so broken wiring fails a deploy instead of a request
- 🛠️ **A CLI**: `gacela-container compile|report|validate` — no console framework, `psr/container` stays the only runtime dependency
- 🎀 **Built to be Wrapped**: `withSelfReference()` hands a decorator's facade to service closures, so composing over the `final` container costs one call

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
| Property injection | ✅ `#[Inject]` | ❌ | ❌ | ✅ | via config |
| Lazy services | ✅ native lazy objects (PHP 8.4+), attribute or `lazy()`, no proxy class | ❌ | ❌ | ✅ via proxy library | ✅ |
| Compiled resolution | ✅ plans + generated factories | ❌ | ❌ | ✅ | ✅ |
| Reflection shared across sibling containers | ✅ `PlanCache`, in-process | ❌ | ❌ | via APCu cache | n/a — dumped once |
| Child/scope containers | ✅ inherits without copying | ❌ | ❌ | ❌ | ❌ |
| Contextual bindings | ✅ | ❌ | ✅ | definitions | ✅ |
| Tags | ✅ list or keyed map | ❌ | ✅ | ❌ | ✅ |
| Resolution hooks | ✅ `afterResolving()` | ❌ | ✅ | ❌ | ❌ |
| Invoke any callable | ✅ `resolve()` | ❌ | ✅ `call()` | ✅ `call()` | ❌ |
| Circular dependencies | ✅ named exception + path | ❌ | ✅ | ✅ | ✅ at compile time |
| Introspection | ✅ `stats()`, dependency tree, `compileReport()`, typo hints | ❌ | ❌ | limited | ✅ via console |
| Array access | ✅ | ✅ | ✅ | ❌ | ❌ |
| Definitions as data | ✅ arrays, PHP + JSON + YAML files | ❌ | ❌ | ✅ | ✅ |
| YAML definition files | ✅ optional, via `symfony/yaml` — never a hard dependency | ❌ | ❌ | ✅ | ✅ |
| XML definition files | ❌ [by design](https://github.com/gacela-project/container/issues/139) — parse it and `load()` the array | ❌ | ❌ | ❌ | ✅ |
| Build-time validation | ✅ `validate()`, `gacela-container validate` | ❌ | ❌ | ❌ | ✅ via compiler passes |
| Compiler passes / extensions | ❌ [by design](https://github.com/gacela-project/container/issues/140) — packages [expose definitions](docs/cookbook.md#let-a-package-register-its-own-services) instead | ❌ | ❌ | ❌ | ✅ |

**Use this if** you want Pimple's footprint with real autowiring, or Laravel's
container API without Laravel — plus lazy services, per-request scopes, and a
compiled cache that skips reflection entirely.

**Look elsewhere if** you need XML definitions or compiler-pass style extension
points. Both are deliberate boundaries rather than a backlog — XML has no
canonical mapping to a definition array short of inventing a schema
([#139](https://github.com/gacela-project/container/issues/139)), and passes
operate on a definition set an autowiring container mostly does not have
([#140](https://github.com/gacela-project/container/issues/140)) — the two
things passes are actually reached for are covered instead: `validate()` gives
the build-time feedback, and a package registers services by exposing
definitions for `load()`. Each issue records the reasoning and what would change
it. YAML *is* supported, as a
`suggest` — install `symfony/yaml` and `loadFile()` reads it; the runtime
requirement is still `psr/container` alone.

## Documentation

| Guide | What's inside |
|-------|---------------|
| [Getting Started](docs/getting-started.md) | Installation, basic usage, how resolution works |
| [Bindings & Registration](docs/bindings.md) | Constructor bindings, `bind()`/`singleton()`, contextual bindings, aliasing |
| [Definitions as Data](docs/definitions.md) | `load()`/`loadFile()`: wiring from arrays, PHP, JSON and YAML files |
| [Resolving Services](docs/resolution.md) | `get()`, `make()`, `getOrFail()`, `resolve()`, transient vs. shared |
| [PHP 8 Attributes](docs/attributes.md) | `#[Inject]`, `#[Singleton]`, `#[Factory]`, `#[Lazy]` |
| [Managing Services](docs/services.md) | Factories, extending, protecting closures, introspection |
| [Scopes](docs/scopes.md) | Child containers: inherited registration, per-request lifetimes |
| [Performance & Compilation](docs/performance.md) | `warmUp()`, compiled cache, generated factories, `validate()`, the CLI |
| [Cookbook](docs/cookbook.md) | Recipes: testing, config, plugins, decorating, debugging |
| [Error Handling](docs/error-handling.md) | Every exception, what causes it, how to fix it |
| [Best Practices](docs/best-practices.md) | Recommended patterns |
| [API Reference](docs/api-reference.md) | Full method, static, and attribute reference |
| [Backward Compatibility](docs/backward-compatibility.md) | What semver covers here, and what it does not |
| [Upgrade Guide](UPGRADE.md) | Migrating to 2.0, and 0.10.0 to 1.0.0 |

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
