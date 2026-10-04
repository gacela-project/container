<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\PlanCache;
use GacelaTest\Fake\ClassWithObjectDependencies;
use GacelaTest\Fake\Person;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * A plan cache carried from one process to the next: what a request planned,
 * the following request starts with.
 */
final class PlanCacheFileTest extends TestCase
{
    private string $directory;

    private string $file;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'plan-cache-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->file = $this->directory . DIRECTORY_SEPARATOR . 'plans.php';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        @rmdir($this->directory);
    }

    public function test_a_cache_read_back_holds_what_was_planned_before_it_was_written(): void
    {
        $plans = new PlanCache();
        (new Container([], [], [], $plans))->get(ClassWithObjectDependencies::class);

        $plans->writeTo($this->file);
        $restored = PlanCache::fromFile($this->file);

        self::assertContains(ClassWithObjectDependencies::class, $restored->classes());
        self::assertContains(Person::class, $restored->classes());
        self::assertInstanceOf(
            ClassWithObjectDependencies::class,
            (new Container([], [], [], $restored))->get(ClassWithObjectDependencies::class),
        );
    }

    public function test_a_missing_file_gives_an_empty_cache(): void
    {
        self::assertSame(0, PlanCache::fromFile($this->file)->count());
    }

    public function test_a_file_from_another_format_gives_an_empty_cache(): void
    {
        file_put_contents($this->file, "<?php return ['format' => -1, 'plans' => []];");

        self::assertSame(0, PlanCache::fromFile($this->file)->count());
    }

    public function test_a_file_written_under_another_build_gives_an_empty_cache(): void
    {
        $plans = new PlanCache();
        (new Container([], [], [], $plans))->get(Person::class);
        $plans->writeTo($this->file, 'build-1');

        self::assertSame(0, PlanCache::fromFile($this->file, 'build-2')->count());
        self::assertContains(Person::class, PlanCache::fromFile($this->file, 'build-1')->classes());
    }

    public function test_writing_leaves_only_the_file_behind(): void
    {
        $plans = new PlanCache();
        (new Container([], [], [], $plans))->get(Person::class);

        $plans->writeTo($this->file);
        $plans->writeTo($this->file);

        self::assertSame(['.', '..', 'plans.php'], scandir($this->directory));
    }
}
