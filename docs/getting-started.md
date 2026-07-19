# Getting Started

[← Back to index](../README.md#documentation)

## Installation

```bash
composer require gacela-project/container
```

Requirements:

- PHP >= 8.1
- PSR-11 Container Interface

## Basic Usage

```php
use Gacela\Container\Container;

// Simple auto-wiring
$container = new Container();
$instance = $container->get(YourClass::class);
```

## How It Works

The container automatically resolves dependencies based on type hints:

- **Primitive types**: use their default values (a default must be provided)
- **Classes**: instantiated and their dependencies resolved recursively
- **Interfaces**: resolved using the bindings defined in the container

### Example

```php
class UserService {
    public function __construct(
        private UserRepository $repository,
        private LoggerInterface $logger,
    ) {}
}

class UserRepository {
    public function __construct(private PDO $pdo) {}
}

// Setup
$bindings = [
    LoggerInterface::class => FileLogger::class,
    PDO::class => new PDO('mysql:host=localhost;dbname=app', 'user', 'pass'),
];

$container = new Container($bindings);

// Auto-resolves UserService -> UserRepository -> PDO
$service = $container->get(UserService::class);
```

## Where to next

- [Bindings & registration](bindings.md)
- [Resolving services](resolution.md)
- [PHP 8 attributes](attributes.md)
- [Full API reference](api-reference.md)
