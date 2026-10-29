<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\CacheFile;
use Gacela\Container\Container;
use Gacela\Container\Exception\ContainerException;
use Gacela\Container\PlanCache;
use GacelaTest\Fake\ClassWithoutDependencies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

use function dirname;
use function function_exists;
use function is_string;

final class CompiledCacheTrustTest extends TestCase
{
    private string $directory;

    private string $file;

    private string $marker;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/gacela-cache-trust-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o700);
        $this->file = $this->directory . '/cache.php';
        $this->marker = $this->directory . '/executed';
    }

    protected function tearDown(): void
    {
        chmod($this->directory, 0o700);
        @unlink($this->file);
        @unlink($this->marker);
        @unlink($this->directory . '/nested/cache.php');
        @rmdir($this->directory . '/nested');
        rmdir($this->directory);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function loaders(): iterable
    {
        yield 'plans' => ['plans'];
        yield 'factories' => ['factories'];
        yield 'plan cache fallback' => ['plan-cache'];
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function writableFiles(): iterable
    {
        foreach (self::loaders() as $name => [$loader]) {
            yield $name . ' group writable' => [$loader, 0o620];
            yield $name . ' world writable' => [$loader, 0o602];
        }
    }

    #[DataProvider('writableFiles')]
    public function test_writable_cache_files_are_refused_before_execution(string $loader, int $mode): void
    {
        $this->requirePosixPermissions();
        $this->plant($this->file);
        chmod($this->file, $mode);

        $this->assertRefused($loader, $this->file);
    }

    #[DataProvider('loaders')]
    public function test_a_non_sticky_writable_directory_is_refused_before_execution(string $loader): void
    {
        $this->requirePosixPermissions();
        $this->plant($this->file);
        chmod($this->directory, 0o777);

        $this->assertRefused($loader, $this->file);
    }

    #[DataProvider('loaders')]
    public function test_a_writable_ancestor_directory_is_refused_before_execution(string $loader): void
    {
        $this->requirePosixPermissions();
        mkdir($this->directory . '/nested', 0o700);
        $file = $this->directory . '/nested/cache.php';
        $this->plant($file);
        chmod($this->directory, 0o770);

        $this->assertRefused($loader, $file);
    }

    #[DataProvider('loaders')]
    public function test_group_and_world_readable_caches_still_load(string $loader): void
    {
        $this->requirePosixPermissions();
        $this->write($loader);

        foreach ([0o640, 0o644] as $mode) {
            chmod($this->file, $mode);
            self::assertArrayHasKey(ClassWithoutDependencies::class, $this->load($loader, $this->file));
        }
    }

    public function test_temporary_creation_never_falls_back_from_a_missing_directory(): void
    {
        self::assertFalse(CacheFile::createTemporary($this->directory . '/missing'));
        self::assertSame(['.', '..'], scandir($this->directory));
    }

    #[DataProvider('loaders')]
    public function test_a_cache_in_a_sticky_directory_still_loads(string $loader): void
    {
        $this->requirePosixPermissions();
        $this->write($loader);
        chmod($this->directory, 0o1777);

        self::assertArrayHasKey(ClassWithoutDependencies::class, $this->load($loader, $this->file));
    }

    #[DataProvider('loaders')]
    public function test_a_real_foreign_owned_file_is_not_included(string $loader): void
    {
        $file = '/etc/hosts';
        if (!function_exists('posix_geteuid') || !is_readable($file) || fileowner($file) === posix_geteuid()) {
            self::markTestSkipped('A readable file owned by another effective user is unavailable');
        }

        $refused = false;
        ob_start();
        try {
            try {
                $result = $this->load($loader, $file);
            } catch (ContainerException) {
                $refused = true;
            }
        } finally {
            $output = ob_get_clean();
        }

        self::assertSame('', $output, 'Including a plain text file would emit its contents');
        if ($loader === 'plan-cache') {
            self::assertSame([], $result ?? null);
        } else {
            self::assertTrue($refused);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_staging_files_are_private_before_any_code_is_written(): void
    {
        $this->requirePosixPermissions();
        $previous = umask(0);
        $temporary = false;

        try {
            $temporary = CacheFile::createTemporary($this->directory);
            self::assertIsString($temporary);
            self::assertSame(realpath($this->directory), dirname($temporary));
            self::assertSame('', file_get_contents($temporary));
            self::assertSame(0o600, fileperms($temporary) & 0o777);
        } finally {
            umask($previous);
            if (is_string($temporary)) {
                unlink($temporary);
            }
        }
    }

    #[DataProvider('loaders')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_published_caches_remain_private_under_a_permissive_umask(string $writer): void
    {
        $this->requirePosixPermissions();
        $previous = umask(0);

        try {
            $this->write($writer);

            self::assertSame(0o600, fileperms($this->file) & 0o777);
            self::assertArrayHasKey(ClassWithoutDependencies::class, $this->load($writer, $this->file));
            self::assertSame(['.', '..', 'cache.php'], scandir($this->directory));
        } finally {
            umask($previous);
        }
    }

    #[DataProvider('loaders')]
    public function test_writing_into_an_unsafe_directory_leaves_the_existing_cache_unchanged(string $writer): void
    {
        $this->requirePosixPermissions();
        file_put_contents($this->file, 'existing cache');
        chmod($this->directory, 0o777);

        try {
            $this->write($writer);
            self::fail('An unsafe cache directory must be refused');
        } catch (ContainerException) {
            self::assertSame('existing cache', file_get_contents($this->file));
            self::assertSame(['.', '..', 'cache.php'], scandir($this->directory));
        }
    }

    private function plant(string $file): void
    {
        file_put_contents($file, '<?php file_put_contents(' . var_export($this->marker, true)
            . ", 'executed'); return ['format' => 1, 'plans' => [], 'factories' => [], 'stamps' => []];");
        chmod($file, 0o600);
    }

    private function assertRefused(string $loader, string $file): void
    {
        try {
            $result = $this->load($loader, $file);
            self::assertSame('plan-cache', $loader, 'Direct loaders must throw for untrusted files');
            self::assertSame([], $result);
        } catch (ContainerException) {
            self::assertNotSame('plan-cache', $loader, 'PlanCache::fromFile must keep its empty-cache fallback');
        } finally {
            self::assertFileDoesNotExist($this->marker, 'The planted PHP must never execute');
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function load(string $loader, string $file): array
    {
        return match ($loader) {
            'plans' => Container::loadCompiledCache($file),
            'factories' => Container::loadCompiledFactories($file),
            default => array_fill_keys(PlanCache::fromFile($file)->classes(), true),
        };
    }

    private function write(string $writer): void
    {
        $container = new Container();
        if ($writer === 'factories') {
            $container->writeCompiledFactories([ClassWithoutDependencies::class], $this->file);
        } elseif ($writer === 'plans') {
            $container->writeCompiledCache([ClassWithoutDependencies::class], $this->file);
        } else {
            $plans = new PlanCache();
            (new Container([], [], [], $plans))->get(ClassWithoutDependencies::class);
            $plans->writeTo($this->file);
        }
    }

    private function requirePosixPermissions(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX permissions are unavailable on Windows');
        }
    }
}
