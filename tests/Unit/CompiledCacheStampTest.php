<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use ArrayObject;
use Gacela\Container\CacheStamp;
use Gacela\Container\Container;
use Gacela\Container\Exception\ContainerException;
use GacelaTest\Fake\ClassWithoutDependencies;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_put_contents;
use function random_bytes;
use function sys_get_temp_dir;
use function touch;
use function uniqid;
use function unlink;

/**
 * A compiled entry says what it was compiled from, so that an entry compiled
 * against a constructor that has since changed is dropped instead of served as
 * if current. Dropping it must behave exactly like never having written it:
 * the class falls back to reflection, and nothing fails.
 */
final class CompiledCacheStampTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        $this->files = [];
    }

    public function test_an_entry_whose_class_file_changed_is_dropped(): void
    {
        [$class, $classFile] = $this->fixtureClass();
        $cache = $this->cacheFile();

        (new Container())->writeCompiledCache([$class, ClassWithoutDependencies::class], $cache);

        self::assertArrayHasKey($class, Container::loadCompiledCache($cache));

        $this->changeFile($classFile);

        $plans = Container::loadCompiledCache($cache);

        self::assertArrayNotHasKey($class, $plans);
        // Only the entry that went stale is dropped; the rest of the file is
        // still a perfectly good cache.
        self::assertArrayHasKey(ClassWithoutDependencies::class, $plans);
    }

    public function test_a_dropped_entry_resolves_through_reflection(): void
    {
        [$class, $classFile] = $this->fixtureClass();
        $cache = $this->cacheFile();

        (new Container())->writeCompiledCache([$class], $cache);
        $this->changeFile($classFile);

        $container = new Container([], [], Container::loadCompiledCache($cache));

        // A stale entry has to degrade, never fatal: the class is built the
        // slow way rather than with the argument list it used to have.
        self::assertInstanceOf($class, $container->get($class));
    }

    public function test_an_entry_whose_class_file_is_gone_is_dropped(): void
    {
        [$class, $classFile] = $this->fixtureClass();
        $cache = $this->cacheFile();

        (new Container())->writeCompiledCache([$class], $cache);
        unlink($classFile);

        self::assertArrayNotHasKey($class, Container::loadCompiledCache($cache));
    }

    public function test_a_matching_build_stamp_takes_the_whole_file(): void
    {
        [$class, $classFile] = $this->fixtureClass();
        $cache = $this->cacheFile();

        (new Container())->writeCompiledCache([$class], $cache, 'deploy-1');
        $this->changeFile($classFile);

        // The point of the build stamp is that no entry is stat'ed at all, so
        // a changed file is not noticed — the deploy id is the promise.
        self::assertArrayHasKey($class, Container::loadCompiledCache($cache, 'deploy-1'));
    }

    public function test_a_build_stamp_that_does_not_match_discards_everything(): void
    {
        $cache = $this->cacheFile();

        (new Container())->writeCompiledCache([ClassWithoutDependencies::class], $cache, 'deploy-1');

        self::assertSame([], Container::loadCompiledCache($cache, 'deploy-2'));
    }

    public function test_loading_without_a_build_stamp_still_checks_each_entry(): void
    {
        [$class, $classFile] = $this->fixtureClass();
        $cache = $this->cacheFile();

        (new Container())->writeCompiledCache([$class, ClassWithoutDependencies::class], $cache, 'deploy-1');
        $this->changeFile($classFile);

        $plans = Container::loadCompiledCache($cache);

        self::assertArrayNotHasKey($class, $plans);
        self::assertArrayHasKey(ClassWithoutDependencies::class, $plans);
    }

    public function test_a_build_stamp_is_ignored_when_the_file_carries_none(): void
    {
        $cache = $this->cacheFile();

        (new Container())->writeCompiledCache([ClassWithoutDependencies::class], $cache);

        self::assertArrayHasKey(
            ClassWithoutDependencies::class,
            Container::loadCompiledCache($cache, 'deploy-1'),
        );
    }

    public function test_a_generated_factory_is_dropped_when_its_class_file_changes(): void
    {
        [$class, $classFile] = $this->fixtureClass();
        $cache = $this->cacheFile();

        (new Container())->writeCompiledFactories([$class, ClassWithoutDependencies::class], $cache);

        $factories = Container::loadCompiledFactories($cache);
        self::assertArrayHasKey($class, $factories);
        self::assertInstanceOf($class, $factories[$class]());

        $this->changeFile($classFile);

        // A generated `new` expression pins an argument list exactly as a plan
        // does, and goes stale for the same reason.
        $factories = Container::loadCompiledFactories($cache);
        self::assertArrayNotHasKey($class, $factories);
        self::assertArrayHasKey(ClassWithoutDependencies::class, $factories);
    }

    public function test_generated_factories_honour_the_build_stamp(): void
    {
        $cache = $this->cacheFile();

        (new Container())->writeCompiledFactories([ClassWithoutDependencies::class], $cache, 'deploy-1');

        self::assertSame([], Container::loadCompiledFactories($cache, 'deploy-2'));
        self::assertArrayHasKey(
            ClassWithoutDependencies::class,
            Container::loadCompiledFactories($cache, 'deploy-1'),
        );
    }

    public function test_a_dropped_factory_leaves_the_container_resolving_normally(): void
    {
        [$class, $classFile] = $this->fixtureClass();
        $cache = $this->cacheFile();

        (new Container())->writeCompiledFactories([$class], $cache);
        $this->changeFile($classFile);

        $container = new Container();
        $container->useCompiledFactories(Container::loadCompiledFactories($cache));

        self::assertInstanceOf($class, $container->get($class));
    }

    public function test_a_cache_written_in_another_format_is_refused(): void
    {
        $cache = $this->cacheFile();
        file_put_contents($cache, "<?php return ['format' => 999, 'plans' => []];");

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/format this version does not read/');

        Container::loadCompiledCache($cache);
    }

    public function test_a_cache_without_the_expected_entries_is_refused(): void
    {
        $cache = $this->cacheFile();
        file_put_contents($cache, "<?php return ['format' => 1, 'build' => null, 'stamps' => []];");

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/did not return an array/');

        Container::loadCompiledCache($cache);
    }

    public function test_a_factories_file_read_as_plans_is_refused(): void
    {
        $cache = $this->cacheFile();
        (new Container())->writeCompiledFactories([ClassWithoutDependencies::class], $cache);

        $this->expectException(ContainerException::class);

        Container::loadCompiledCache($cache);
    }

    public function test_an_entry_for_a_class_with_no_file_survives_a_round_trip(): void
    {
        $cache = $this->cacheFile();

        // An internal class has no file to stat, so it is written with an
        // empty stamp and must still come back rather than be dropped by a
        // check it can never pass.
        (new Container())->writeCompiledCache([ArrayObject::class], $cache);

        self::assertArrayHasKey(ArrayObject::class, Container::loadCompiledCache($cache));
    }

    public function test_a_class_with_no_file_of_its_own_is_never_stale(): void
    {
        // Internal classes and classes that vanished cannot be compared, and
        // an entry that cannot be compared is kept: no edit can invalidate it.
        self::assertNull(CacheStamp::of(ArrayObject::class));
        self::assertNull(CacheStamp::of('No\\Such\\Class'));
        self::assertTrue(CacheStamp::isCurrent(null));
    }

    /**
     * A class in a file this test owns, so its mtime and size can be changed
     * under the cache without touching a fixture other tests depend on.
     *
     * @return array{class-string, string}
     */
    private function fixtureClass(): array
    {
        $name = 'Stamped' . bin2hex(random_bytes(8));
        $file = sys_get_temp_dir() . '/' . $name . '.php';

        file_put_contents($file, "<?php\n\nnamespace GacelaTest\\Tmp;\n\nfinal class {$name}\n{\n}\n");
        $this->files[] = $file;

        require $file;

        /** @var class-string $class */
        $class = 'GacelaTest\\Tmp\\' . $name;

        return [$class, $file];
    }

    private function cacheFile(): string
    {
        $file = sys_get_temp_dir() . '/' . uniqid('gacela_stamped_cache_', true) . '.php';
        $this->files[] = $file;

        return $file;
    }

    /**
     * Both halves of the stamp move: size within the second, mtime for the
     * filesystems that only keep whole seconds.
     */
    private function changeFile(string $file): void
    {
        file_put_contents($file, "<?php\n\n// edited after the cache was written\n");
        touch($file, 2_000_000_000);
    }
}
