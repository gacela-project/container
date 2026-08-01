<?php

declare(strict_types=1);

namespace Gacela\Container;

use Gacela\Container\Exception\ContainerException;
use JsonException;
use Symfony\Component\Yaml\Yaml as SymfonyYaml;
use Throwable;

use function array_diff;
use function array_intersect;
use function array_key_exists;
use function array_keys;
use function array_map;
use function class_exists;
use function count;
use function file_get_contents;
use function implode;
use function is_array;
use function is_callable;
use function is_file;
use function is_object;
use function is_readable;
use function is_string;
use function json_decode;
use function pathinfo;
use function sprintf;
use function strtolower;

/**
 * Turns a definitions array into the imperative registration calls it stands
 * for.
 *
 * Deliberately not a second registration path: every entry ends up calling the
 * same bind()/singleton()/set()/alias()/tag() a hand-written bootstrap would,
 * so laziness, freezing and scope semantics are whatever those already do.
 *
 * @internal
 * Not covered by backward compatibility: this class is an implementation
 * detail of Container and may change or disappear in any release
 */
final class DefinitionLoader
{
    /**
     * The keys that register something for the id. At most one may appear in an
     * entry: two of them would leave the winner up to iteration order.
     */
    private const BINDING_KEYS = ['singleton', 'value', 'factory', 'alias'];

    private const ALLOWED_KEYS = ['singleton', 'value', 'factory', 'alias', 'tags'];

    public function __construct(
        private ContainerInterface $container,
    ) {
    }

    /**
     * @param array<array-key, mixed> $definitions
     * @param string|null $file the file the definitions came from, for error messages
     * @param (callable(string): void)|null $onRegistered called with each id as
     *   it is registered, in definition order
     *
     * @return list<string> every id registered, in definition order
     */
    public function load(array $definitions, ?string $file = null, ?callable $onRegistered = null): array
    {
        $registered = [];

        /** @var mixed $definition */
        foreach ($definitions as $id => $definition) {
            if (!is_string($id)) {
                throw ContainerException::invalidDefinition(
                    (string) $id,
                    'definition ids must be strings; this looks like a list rather than a map of id => definition',
                    $file,
                );
            }

            $this->apply($id, $definition, $file);
            $registered[] = $id;

            if ($onRegistered !== null) {
                $onRegistered($id);
            }
        }

        return $registered;
    }

    /**
     * @param (callable(string): void)|null $onRegistered see load()
     *
     * @return list<string> every id registered, in definition order
     */
    public function loadFile(string $file, ?callable $onRegistered = null): array
    {
        if (!is_file($file) || !is_readable($file)) {
            throw ContainerException::definitionFileUnreadable($file);
        }

        $definitions = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'php' => $this->readPhpFile($file),
            'json' => $this->readJsonFile($file),
            'yaml', 'yml' => $this->readYamlFile($file),
            default => throw ContainerException::definitionFileUnsupported($file),
        };

        return $this->load($definitions, $file, $onRegistered);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function readPhpFile(string $file): array
    {
        /**
         * @psalm-suppress UnresolvableInclude
         *
         * @var mixed $definitions
         */
        $definitions = require $file;

        if (!is_array($definitions)) {
            throw ContainerException::definitionFileInvalid($file, 'it did not return an array');
        }

        return $definitions;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function readJsonFile(string $file): array
    {
        $contents = file_get_contents($file);

        if ($contents === false) {
            throw ContainerException::definitionFileUnreadable($file);
        }

        try {
            /** @var mixed $definitions */
            $definitions = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw ContainerException::definitionFileInvalid($file, $exception->getMessage());
        }

        if (!is_array($definitions)) {
            throw ContainerException::definitionFileInvalid($file, 'it does not contain a JSON object');
        }

        return $definitions;
    }

    /**
     * YAML, when a parser is already installed.
     *
     * Deliberately not a dependency. `psr/container` is the only thing this
     * library requires at runtime and a container whose pitch is Pimple's
     * footprint does not get to quietly add a parser. symfony/yaml is a
     * `suggest`, and a .yaml file without it throws saying exactly that rather
     * than failing on an undefined class — the `load()` one-liner has always
     * worked and still does for anyone who would rather parse it themselves.
     *
     * @return array<string, mixed>
     */
    private function readYamlFile(string $file): array
    {
        if (!class_exists(SymfonyYaml::class)) {
            throw ContainerException::yamlParserMissing($file);
        }

        try {
            /** @var mixed $definitions */
            $definitions = SymfonyYaml::parseFile($file);
        } catch (Throwable $exception) {
            throw ContainerException::definitionFileInvalid($file, $exception->getMessage());
        }

        if (!is_array($definitions)) {
            throw ContainerException::definitionFileInvalid($file, 'it does not contain a YAML mapping');
        }

        /** @var array<string, mixed> */
        return $definitions;
    }

    private function apply(string $id, mixed $definition, ?string $file): void
    {
        if (is_string($definition)) {
            $this->bindConcrete($id, $definition, $file);
            return;
        }

        if (!is_array($definition)) {
            throw ContainerException::invalidDefinition(
                $id,
                'expected a class-string or an array of definition keys',
                $file,
            );
        }

        $this->assertKeys($id, $definition, $file);

        if (isset($definition['tags'])) {
            $this->applyTags($id, $definition['tags'], $file);
        }

        // Checked after the keys so an entry naming both a typo and a real key
        // is reported as the typo, which is the more useful of the two.
        foreach (self::BINDING_KEYS as $key) {
            if (array_key_exists($key, $definition)) {
                $this->applyBinding($id, $key, $definition[$key], $file);
                return;
            }
        }
    }

    /**
     * @param array<array-key, mixed> $definition
     */
    private function assertKeys(string $id, array $definition, ?string $file): void
    {
        if ($definition === []) {
            throw ContainerException::invalidDefinition(
                $id,
                'the definition is empty; expected one of: ' . implode(', ', self::ALLOWED_KEYS),
                $file,
            );
        }

        $keys = array_keys($definition);

        $unknown = array_diff($keys, self::ALLOWED_KEYS);
        if ($unknown !== []) {
            throw ContainerException::invalidDefinition(
                $id,
                sprintf(
                    'unknown key(s) %s; allowed: %s',
                    implode(', ', array_map(static fn (mixed $key): string => "'" . (string) $key . "'", $unknown)),
                    implode(', ', self::ALLOWED_KEYS),
                ),
                $file,
            );
        }

        $bindings = array_intersect(self::BINDING_KEYS, $keys);
        if (count($bindings) > 1) {
            throw ContainerException::invalidDefinition(
                $id,
                sprintf('%s are mutually exclusive; pick one', implode(' and ', $bindings)),
                $file,
            );
        }
    }

    private function applyBinding(string $id, string $key, mixed $value, ?string $file): void
    {
        match ($key) {
            'singleton' => $this->applySingleton($id, $value, $file),
            'value' => $this->container->set($id, $value),
            'factory' => $this->applyFactory($id, $value, $file),
            default => $this->applyAlias($id, $value, $file),
        };
    }

    private function bindConcrete(string $id, string $concrete, ?string $file): void
    {
        if (!class_exists($concrete)) {
            throw ContainerException::invalidDefinition(
                $id,
                sprintf("'%s' is not a class; use ['value' => …] for a scalar or an object", $concrete),
                $file,
            );
        }

        $this->container->bind($id, $concrete);
    }

    private function applySingleton(string $id, mixed $value, ?string $file): void
    {
        if (is_string($value)) {
            $this->container->singleton($id, $this->assertClassExists($id, 'singleton', $value, $file));
            return;
        }

        if (!is_object($value) && !is_callable($value)) {
            throw ContainerException::invalidDefinition(
                $id,
                "'singleton' expects a class-string, a callable, or an object",
                $file,
            );
        }

        /** @var callable|object $value */
        $this->container->singleton($id, $value);
    }

    private function applyFactory(string $id, mixed $value, ?string $file): void
    {
        // A string is rejected outright: 'strlen' is callable, and a
        // class-string is not, which is exactly the confusion to avoid here.
        if (is_string($value) || !is_callable($value)) {
            throw ContainerException::invalidDefinition(
                $id,
                "'factory' expects a callable, so it cannot come from a JSON file",
                $file,
            );
        }

        $this->container->bind($id, $value);
    }

    private function applyAlias(string $id, mixed $value, ?string $file): void
    {
        if (!is_string($value) || $value === '') {
            throw ContainerException::invalidDefinition($id, "'alias' expects the id it points at", $file);
        }

        $this->container->alias($id, $value);
    }

    private function applyTags(string $id, mixed $tags, ?string $file): void
    {
        if (!is_array($tags) || $tags === []) {
            throw ContainerException::invalidDefinition($id, "'tags' expects a non-empty list of tag names", $file);
        }

        /** @var mixed $tag */
        foreach ($tags as $tag) {
            if (!is_string($tag) || $tag === '') {
                throw ContainerException::invalidDefinition($id, "'tags' expects a non-empty list of tag names", $file);
            }

            $this->container->tag($id, $tag);
        }
    }

    /**
     * @return class-string the concrete, proven loadable
     */
    private function assertClassExists(string $id, string $key, string $concrete, ?string $file): string
    {
        if (!class_exists($concrete)) {
            throw ContainerException::invalidDefinition(
                $id,
                sprintf("'%s' names '%s', which is not a class", $key, $concrete),
                $file,
            );
        }

        return $concrete;
    }
}
