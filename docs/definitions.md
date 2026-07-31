# Definitions as Data

[← Back to index](../README.md#documentation)

`load()` registers services from an array, so wiring can be shipped by a package
and overridden per environment instead of being hand-written in PHP.

```php
$container->load([
    // interface => concrete
    LoggerInterface::class => FileLogger::class,

    // explicit lifetime
    Database::class => ['singleton' => DatabasePool::class],

    // a scalar or config value
    'db.dsn' => ['value' => 'pgsql://localhost/app'],

    // another name for an id
    'logger' => ['alias' => LoggerInterface::class],

    // group ids so they can be resolved together
    Metrics::class => ['singleton' => Metrics::class, 'tags' => ['reporters']],
]);
```

Nothing new happens at resolution time. Each entry calls the registration method
it stands for, so laziness, freezing, contextual bindings and
[scopes](scopes.md) behave exactly as they do for the imperative equivalent.

## Entry forms

| Definition | Equivalent to | Notes |
|---|---|---|
| `Concrete::class` | `bind($id, Concrete::class)` | the string must name a loadable class |
| `['singleton' => …]` | `singleton($id, …)` | a class-string, a callable (memoized), or an object |
| `['value' => …]` | `set($id, …)` | any value: scalar, array, object |
| `['factory' => …]` | `bind($id, $callable)` | a callable, invoked on **every** resolution |
| `['alias' => 'other.id']` | `alias($id, 'other.id')` | |
| `['tags' => ['a', 'b']]` | `tag($id, 'a')`, `tag($id, 'b')` | |

`tags` may be combined with one other key. The four binding keys are mutually
exclusive — naming two of them would leave the winner up to iteration order, so
it is an error rather than a coin flip.

A bare string is always read as a class-string. Use `['value' => …]` for a
string you mean literally:

```php
$container->load(['db.dsn' => 'pgsql://localhost/app']);            // ✗ not a class
$container->load(['db.dsn' => ['value' => 'pgsql://localhost/app']]); // ✓
```

## From a file

`loadFile()` reads a `.php` file that returns an array, or a `.json` file:

```php
$container->loadFile(__DIR__ . '/config/services.php');
$container->loadFile(__DIR__ . '/config/services.json');
```

```php
// config/services.php
return [
    LoggerInterface::class => FileLogger::class,
    'db.dsn' => ['value' => getenv('DATABASE_URL')],
    'clock' => ['factory' => fn () => new SystemClock()],
];
```

```json
{
    "App\\LoggerInterface": "App\\FileLogger",
    "db.dsn": { "value": "pgsql://localhost/app" }
}
```

`factory` needs a callable, so it is a PHP-file feature; a JSON file naming one
is rejected with a message saying so.

### YAML, if you already have a parser

`loadFile()` reads `.yaml` and `.yml` too, using `symfony/yaml`:

```php
$container->loadFile(__DIR__ . '/config/services.yaml');
```

```yaml
'App\Contract\RepositoryInterface': 'App\Doctrine\Repository'
db.dsn:
  value: 'pgsql://localhost/app'
mailer:
  singleton: 'App\Mailer'
  tags: [notifiers]
```

It is a **`suggest`, never a dependency**. `psr/container` is this package's
only runtime requirement and it stays that way, so a `.yaml` file without a
parser installed throws telling you what to install rather than failing on an
undefined class. Nothing else changes: the parsed array goes through exactly the
same `load()` every other format does.

Not having it is still fine. Parsing it yourself has always worked, needs
nothing, and is the answer for any other format — including XML, which has no
canonical mapping to a definition array that would not amount to inventing a
schema:

```php
$container->load(Yaml::parseFile('services.yaml'));
```

## Layering environments

Later keys win, so per-environment configuration is two calls in the right
order:

```php
$container->loadFile(__DIR__ . '/config/services.php');
$container->loadFile(__DIR__ . "/config/services.{$env}.php");
```

`tags` are the exception: they accumulate and dedupe the way `tag()` does, so a
later load adds to a group rather than replacing it.

## Loading after something has resolved

Loading obeys the same rules as the calls it stands for. A `value` whose id has
already been handed out is [frozen](services.md), and replacing it throws rather
than silently swapping an instance somebody is already holding:

```php
$container->load(['db.dsn' => ['value' => 'pgsql://localhost/app']]);
$container->get('db.dsn');                                  // frozen from here

$container->load(['db.dsn' => ['value' => 'other']]);       // ContainerException
```

Bindings are not frozen by resolution, so re-binding an abstract keeps working —
it affects everything resolved from that point on.

## Errors

Every failure names the offending id and, when it came from a file, the file:

- an unknown key, with the allowed set listed
- two binding keys in one entry
- a value of the wrong type for its key
- an id that is not a string — usually a JSON array where an object was meant
- a missing, unreadable, or unsupported file
- a `.php` file that returns something other than an array, or invalid JSON

All of them are a `ContainerException`. See [error handling](error-handling.md).

## Related

- [Bindings & Registration](bindings.md) — the imperative equivalents
- [Cookbook](cookbook.md#wire-a-container-per-environment) — the layering recipe
- [Scopes](scopes.md) — what a child container inherits
