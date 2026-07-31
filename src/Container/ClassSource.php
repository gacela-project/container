<?php

declare(strict_types=1);

namespace Gacela\Container;

use Closure;
use FilesystemIterator;
use Gacela\Container\Exception\ContainerException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_keys;
use function count;
use function dirname;
use function is_array;
use function is_file;
use function is_readable;
use function is_string;

/**
 * Where the classes to compile come from.
 *
 * Every compile entry point used to take a `list<class-string>` that nothing
 * produced, so each application wrote and maintained it by hand — and a class
 * added to the application but not to the list silently dropped back to the
 * reflection path, with nothing to say so.
 *
 * A source is resolved lazily, once, when a compile call asks for it. Passing a
 * plain list still works everywhere; this is additive.
 *
 * Deliberately not accepted by `warmUp()`. The signatures are otherwise
 * identical and the symmetry is a trap: warming *resolves*, so warming a
 * classmap would construct the application rather than describe it.
 *
 * @api
 */
final class ClassSource
{
    /** @var list<class-string>|null */
    private ?array $resolved = null;

    /**
     * @param Closure(): list<class-string> $discover
     */
    private function __construct(
        private readonly Closure $discover,
    ) {
    }

    /**
     * Composer's classmap, which is authoritative for an `--optimize-autoloader`
     * build and needs no parsing — and is already present in exactly the
     * deployment where compiling is worth doing.
     *
     * @param string|null $classmapFile defaults to the
     *   `vendor/composer/autoload_classmap.php` of the installation this file
     *   is part of
     */
    public static function fromComposerClassmap(?string $classmapFile = null): self
    {
        return new self(static function () use ($classmapFile): array {
            $file = $classmapFile ?? self::locateClassmap();

            if ($file === null || !is_file($file) || !is_readable($file)) {
                throw ContainerException::classmapNotFound($classmapFile);
            }

            /**
             * The path is a caller's, so it cannot be resolved statically —
             * same as the compiled-cache and definition readers.
             *
             * @psalm-suppress UnresolvableInclude
             *
             * @var mixed $map
             */
            $map = require $file;

            if (!is_array($map)) {
                throw ContainerException::classmapNotFound($file);
            }

            /** @var list<class-string> */
            return array_keys($map);
        });
    }

    /**
     * Every class declared under $directories, for dev setups with no optimized
     * autoloader.
     *
     * The files are tokenised rather than loaded to find the declarations, so a
     * file that declares nothing is never included for its side effects. The
     * classes themselves are still autoloaded when they come to be planned —
     * there is no reflecting on a class that was never loaded.
     */
    public static function fromDirectory(string ...$directories): self
    {
        return new self(static function () use ($directories): array {
            $classNames = [];

            foreach ($directories as $directory) {
                foreach (self::declarationsIn($directory) as $className) {
                    $classNames[$className] = true;
                }
            }

            /** @var list<class-string> */
            return array_keys($classNames);
        });
    }

    /**
     * An explicit list, so a caller that already has one can use the same
     * parameter as everything else.
     *
     * @param list<class-string> $classNames
     */
    public static function fromList(array $classNames): self
    {
        return new self(static fn (): array => $classNames);
    }

    /**
     * The classes this source found. Discovery runs once per source; the result
     * is reused, so passing one source to several compile calls scans once.
     *
     * @return list<class-string>
     */
    public function classNames(): array
    {
        return $this->resolved ??= ($this->discover)();
    }

    /**
     * @return list<class-string>
     */
    private static function declarationsIn(string $directory): array
    {
        if (!is_dir($directory)) {
            throw ContainerException::directoryNotFound($directory);
        }

        $classNames = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $contents = @file_get_contents($path);

            if ($contents === false) {
                continue;
            }

            foreach (self::declarationsOf($contents) as $className) {
                $classNames[] = $className;
            }
        }

        return $classNames;
    }

    /**
     * The fully-qualified names of the classes $source declares.
     *
     * Interfaces, traits and enums are left out: none of them can ever be
     * compiled, so listing them would only pad every report with refusals the
     * caller can do nothing about. An abstract class *is* returned — it reads as
     * a class, and the compiler refusing it by name is useful information.
     *
     * @return list<class-string>
     */
    private static function declarationsOf(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);

        $namespace = '';
        $classNames = [];

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = self::readName($tokens, $i, $count);
                continue;
            }

            if ($token[0] !== T_CLASS) {
                continue;
            }

            // `Foo::class` is a T_CLASS too, and an anonymous class is a T_CLASS
            // with no name after it. readName() returns '' for both.
            $previous = $i > 0 ? $tokens[$i - 1] : null;

            if (is_array($previous) && $previous[0] === T_DOUBLE_COLON) {
                continue;
            }

            $name = self::readName($tokens, $i, $count);

            if ($name !== '') {
                /** @var class-string $fqcn */
                $fqcn = $namespace === '' ? $name : $namespace . '\\' . $name;
                $classNames[] = $fqcn;
            }
        }

        return $classNames;
    }

    /**
     * The identifier following the token at $i — a namespace path, or a class
     * name. Empty when the next meaningful token is not a name at all, which is
     * how `{`, `(` and `;` mark an anonymous class or a `namespace\` operator.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function readName(array $tokens, int $i, int $count): string
    {
        $name = '';

        for ($j = $i + 1; $j < $count; ++$j) {
            $token = $tokens[$j];

            if (is_string($token)) {
                if ($token === '{' || $token === '(' || $token === ';') {
                    break;
                }

                continue;
            }

            if ($token[0] === T_WHITESPACE) {
                if ($name !== '') {
                    break;
                }

                continue;
            }

            if ($token[0] === T_STRING || $token[0] === T_NAME_QUALIFIED) {
                $name .= $token[1];
                continue;
            }

            break;
        }

        return $name;
    }

    private static function locateClassmap(): ?string
    {
        // Installed as a dependency this file sits in
        // vendor/gacela-project/container/src/Container; in this repository it
        // sits in src/Container with vendor alongside. Walking up covers both
        // without hardcoding either depth.
        $directory = __DIR__;

        while (true) {
            $candidate = $directory . '/vendor/composer/autoload_classmap.php';

            if (is_file($candidate)) {
                return $candidate;
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                return null;
            }

            $directory = $parent;
        }
    }
}
