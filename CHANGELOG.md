# Changelog

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
