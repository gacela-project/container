# Gacela Resolver

<p align="center">
  <a href="https://github.com/gacela-project/resolver/actions">
    <img src="https://github.com/gacela-project/resolver/workflows/CI/badge.svg" alt="GitHub Build Status">
  </a>
  <a href="https://scrutinizer-ci.com/g/gacela-project/resolver/?branch=main">
    <img src="https://scrutinizer-ci.com/g/gacela-project/resolver/badges/quality-score.png?b=main" alt="Scrutinizer Code Quality">
  </a>
  <a href="https://scrutinizer-ci.com/g/gacela-project/resolver/?branch=main">
    <img src="https://scrutinizer-ci.com/g/gacela-project/resolver/badges/coverage.png?b=main" alt="Scrutinizer Code Coverage">
  </a>
  <a href="https://shepherd.dev/github/gacela-project/resolver">
    <img src="https://shepherd.dev/github/gacela-project/resolver/coverage.svg" alt="Psalm Type-coverage Status">
  </a>
  <a href="https://github.com/gacela-project/resolver/blob/master/LICENSE">
    <img src="https://img.shields.io/badge/License-MIT-green.svg" alt="MIT Software License">
  </a>
</p>

## Installation

```bash
composer require gacela-project/resolver
```

## Usage

You can define a map between an interface and the concrete class that you want to create (or use) when that interface is
found during the process of auto-wiring via its constructor. For example:

```php
$mappingInterfaces = [
  AbstractString::class => StringClass::class),
  ClassInterface::class => new ConcreteClass(/* args */)),
  ComplexInterface::class => new class() implements Foo {/** logic */}),
  FromCallable::class => fn() => new StringClass('From callable')),
];
```

### InstanceCreator

Create an instance by class name.

```php
$creator = new InstanceCreator($mappingInterfaces);

$instance = $creator->createByClassName(YourClass::class);

```

### DependencyResolver

Get the resolved dependencies by class name.

```php
$resolver = new DependencyResolver($mappingInterfaces);

$className = YourClass::class;

$dependencies = $resolver->resolveDependencies($className);
$instance = new $className($dependencies);
```

### Example

A usage example in the Gacela Framework: [AbstractClassResolver](https://github.com/gacela-project/gacela/blob/main/src/Framework/ClassResolver/AbstractClassResolver.php#L145)

