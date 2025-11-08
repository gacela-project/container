# Changelog

## Unreleased

- Improve error messages with actionable suggestions for easier debugging
- Add circular dependency detection with helpful error messages
- Optimize callableKey() to use spl_object_id() instead of md5+var_export
- Add constructor method caching to avoid redundant reflection lookups
- Add introspection methods: getRegisteredServices(), isFactory(), isFrozen(), getBindings()
- Add warmUp() method to pre-resolve dependencies for improved performance
- Improve README with comprehensive examples and best practices
- Fix: Constructor caching now uses concrete class name instead of interface name
- Cache class_exists() and interface_exists() calls for better performance
- Add service aliasing support with alias() method
- Add getDependencyTree() method to inspect class dependencies
- Include resolution chain in error messages for better debugging context
- Refactor: Extract AliasRegistry class to reduce Container complexity
- Refactor: Extract FactoryManager class to reduce Container complexity
- Add generic type annotations for better static analysis support
- Set XDEBUG_MODE=coverage in phpunit.xml for CI compatibility

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
