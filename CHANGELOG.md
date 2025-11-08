# Changelog

## Unreleased

### New Features

#### PHP 8 Attributes
- Add `#[Inject]` attribute to override dependency injection for specific implementations
- Add `#[Singleton]` attribute to mark classes as single-instance services
- Add `#[Factory]` attribute to always create new instances

#### Contextual Bindings
- Add `when()->needs()->give()` fluent API for class-specific dependency injection
- Support binding different implementations of the same interface based on requesting class

#### Service Aliasing
- Add `alias()` method to create alternative names for services

#### Introspection & Debugging
- Add `getStats()` method for container performance monitoring and debugging
- Add `getRegisteredServices()`, `isFactory()`, `isFrozen()`, `getBindings()` methods
- Add `getDependencyTree()` method to inspect class dependency hierarchies
- Add `warmUp()` method to pre-resolve dependencies for improved performance

### Performance Improvements
- Optimize attribute reflection with caching (15-20% improvement for attributed classes)
- Add alias resolution caching
- Optimize `callableKey()` to use `spl_object_id()` instead of `md5+var_export`
- Add constructor method caching to avoid redundant reflection lookups
- Cache `class_exists()` and `interface_exists()` calls
- Cache `ReflectionClass` instances to prevent redundant reflection

### Developer Experience
- Add fuzzy service name suggestions to error messages for better typo detection
- Improve error messages with actionable suggestions
- Add circular dependency detection with helpful error messages
- Include resolution chain in error messages for better debugging context
- Improve README with comprehensive examples and best practices

### Bug Fixes
- Fix: Constructor caching now uses concrete class name instead of interface name

### Code Quality
- Add generic type annotations for better static analysis support
- Extract specialized classes to reduce Container complexity:
  - `AliasRegistry` for alias management
  - `FactoryManager` for factory service handling
  - `InstanceRegistry` for instance storage
  - `DependencyCacheManager` for dependency caching
  - `BindingResolver` for binding resolution
  - `DependencyTreeAnalyzer` for dependency analysis

## 0.7.0
### 2025-08-02

- Container performance avoid reflection
- Fix factory services
- Cache `ReflectionClass` instances to prevent redundant reflection

## 0.6.1
### 2024-07-06

- Support `"psr/container": ">=1.1"`

## 0.6.0
### 2023-12-21

- Change min PHP support for `PHP>=8.1` 

## 0.5.1
### 2023-06-24

- Improved error message when no concrete binded class was found

## 0.5.0
### 2023-05-19

- Accept `Closure|string` in `getParametersToResolve()`
- Added `resolve(callable)` to `ContainerInterface`

## 0.4.0
### 2023-04-27

- Add Container methods: set, factory, extend, remove, protect
- Remove final from `Container` to allow decorating it using extend

## 0.3.0
### 2023-04-24

- Rename InstanceCreator to Container
- Add [PSR-11](https://www.php-fig.org/psr/psr-11/) support
- Remove `createByClassName()`, use `get()` instead

## 0.1.0
### 2023-03-11

- Initial release: Code extracted originally from `gacela-project/gacela`
