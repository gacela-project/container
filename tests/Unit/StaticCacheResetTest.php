<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use FilesystemIterator;
use Gacela\Container\Container;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\LazyService;
use GacelaTest\Fake\ServiceWithInjectedProperty;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

use function str_replace;
use function strlen;
use function substr;

/**
 * The caches that outlive every container.
 *
 * A consumer's own reset can only be honest about what it clears if this
 * package hands it a way in — and only stays honest if a new static property
 * cannot be added here without being wired into the reset. The enumeration
 * test is that guard, and it has to live here: a consumer's own version of it
 * stops at the vendor boundary.
 */
final class StaticCacheResetTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::resetStaticCaches();
    }

    public function test_every_static_property_is_back_at_its_declared_default(): void
    {
        $container = new Container();
        $container->get(ServiceWithInjectedProperty::class);
        $container->get(LazyService::class);

        self::assertNotSame([], self::staticProperties(), 'nothing was cached, so the reset proves nothing');

        Container::resetStaticCaches();

        foreach (self::staticProperties() as $name => $value) {
            self::assertSame(
                self::defaults()[$name],
                $value,
                "{$name} survived resetStaticCaches(); wire it into the reset",
            );
        }
    }

    public function test_the_caches_are_populated_before_the_reset(): void
    {
        Container::resetStaticCaches();

        $container = new Container();
        $container->get(ServiceWithInjectedProperty::class);

        // Guards the guard: if resolution stopped populating these, the test
        // above would pass by measuring nothing.
        $populated = [];
        foreach (self::staticProperties() as $name => $value) {
            if ($value !== self::defaults()[$name]) {
                $populated[] = $name;
            }
        }

        self::assertNotSame([], $populated);
    }

    public function test_resolution_still_works_after_a_reset(): void
    {
        $container = new Container();
        $container->get(ServiceWithInjectedProperty::class);

        Container::resetStaticCaches();

        // The plans are rebuilt, not lost: an #[Inject] property whose plan was
        // just thrown away must still be assigned.
        $service = $container->get(ServiceWithInjectedProperty::class);

        self::assertInstanceOf(ClassWithoutDependencies::class, $service->dependency());
        self::assertInstanceOf(LazyService::class, $container->get(LazyService::class));
    }

    public function test_a_reset_costs_nothing_but_the_reflection_it_throws_away(): void
    {
        $container = new Container();
        $container->singleton(ClassWithoutDependencies::class);
        $first = $container->get(ClassWithoutDependencies::class);

        Container::resetStaticCaches();

        // A singleton the container itself holds is instance state, not static
        // state, and must survive: the reset drops memos of class shape, never
        // anything a container was asked to keep.
        self::assertSame($first, $container->get(ClassWithoutDependencies::class));
    }

    /**
     * Every static property declared anywhere under src/, keyed
     * `Class::$property`, with its current value.
     *
     * @return array<string, mixed>
     */
    private static function staticProperties(): array
    {
        $properties = [];

        foreach (self::packageClasses() as $class) {
            $reflection = new ReflectionClass($class);

            /** @var mixed $value */
            foreach ($reflection->getStaticProperties() as $name => $value) {
                $properties[$reflection->getShortName() . '::$' . $name] = $value;
            }
        }

        return $properties;
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaults(): array
    {
        $defaults = [];

        foreach (self::packageClasses() as $class) {
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getStaticProperties() as $name => $_) {
                /** @var mixed $default */
                $default = $reflection->getDefaultProperties()[$name] ?? null;
                $defaults[$reflection->getShortName() . '::$' . $name] = $default;
            }
        }

        return $defaults;
    }

    /**
     * @return list<class-string>
     */
    private static function packageClasses(): array
    {
        $source = __DIR__ . '/../../src';

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        );

        $classes = [];

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($source) + 1, -4);

            /** @var class-string $class */
            $class = 'Gacela\\' . str_replace('/', '\\', $relative);
            $classes[] = $class;
        }

        return $classes;
    }
}
