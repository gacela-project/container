# Changelog

## Unreleased

- Improved error message when no concrete binded class was found

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
