<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\CompiledCacheWriter;
use Gacela\Container\Container;
use Gacela\Container\PlanCache;
use GacelaTest\Fake\ClassWithoutDependencies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class UnstampableCacheTest extends TestCase
{
    private string $directory;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/gacela-unstampable-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o700);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        rmdir($this->directory);
    }

    public static function writers(): iterable
    {
        foreach (['plans', 'shared plans', 'factories'] as $writer) {
            yield $writer . ' deleted file' => [$writer, false];
            yield $writer . ' evaluated class' => [$writer, true];
        }
    }

    #[DataProvider('writers')]
    public function test_writers_omit_user_classes_without_a_verifiable_file(string $writer, bool $evaluated): void
    {
        [$class, $classFile] = $this->fixture($evaluated);
        $plans = new PlanCache();
        $container = new Container([], [], [], $plans);
        self::assertInstanceOf($class, $container->get($class));
        $file = $this->file('cache.php');

        if (!$evaluated) {
            unlink($classFile);
        }

        match ($writer) {
            'plans' => $container->writeCompiledCache([$class], $file),
            'shared plans' => $plans->writeTo($file),
            'factories' => $container->writeCompiledFactories([$class], $file),
        };

        $entries = $writer === 'factories'
            ? Container::loadCompiledFactories($file)
            : Container::loadCompiledCache($file);

        self::assertArrayNotHasKey($class, $entries);
        if ($writer !== 'factories') {
            self::assertSame([], PlanCache::fromFile($file)->classes());
        }
    }

    public static function unstampedEntries(): iterable
    {
        foreach (['plans', 'factories'] as $section) {
            yield $section . ' explicit null' => [$section, true];
            yield $section . ' missing stamp' => [$section, false];
        }
    }

    #[DataProvider('unstampedEntries')]
    public function test_readers_discard_unstamped_user_entries(string $section, bool $explicitNull): void
    {
        $class = ClassWithoutDependencies::class;
        $plan = (new Container())->compile([$class])[$class];
        $expression = $section === 'plans'
            ? var_export($plan, true)
            : 'static fn (): object => new \\' . $class . '()';
        $file = $this->file('cache.php');
        CompiledCacheWriter::put($file, CompiledCacheWriter::envelope(
            null,
            $explicitNull ? [$class => null] : [],
            $section,
            [var_export($class, true) . ' => ' . $expression . ','],
        ));

        $entries = $section === 'plans'
            ? Container::loadCompiledCache($file)
            : Container::loadCompiledFactories($file);

        self::assertSame([], $entries);
        if ($section === 'plans') {
            self::assertSame([], PlanCache::fromFile($file)->classes());
        }
    }

    public function test_internal_classes_survive_round_trips_without_user_source_files(): void
    {
        $file = $this->file('cache.php');
        $plans = new PlanCache();
        $container = new Container([], [], [], $plans);
        self::assertInstanceOf(stdClass::class, $container->get(stdClass::class));
        $plans->writeTo($file);

        self::assertArrayHasKey(stdClass::class, Container::loadCompiledCache($file));

        $container->writeCompiledFactories([stdClass::class], $file);
        $factories = Container::loadCompiledFactories($file);
        self::assertInstanceOf(stdClass::class, $factories[stdClass::class]());
    }

    public function test_a_factory_with_a_missing_inlined_source_is_omitted(): void
    {
        [$dependency, $dependencyFile] = $this->fixture(false);
        [$consumer] = $this->fixture(false, $dependency);
        unlink($dependencyFile);
        $file = $this->file('cache.php');

        (new Container())->writeCompiledFactories([$consumer, ClassWithoutDependencies::class], $file);
        $factories = Container::loadCompiledFactories($file);

        self::assertArrayNotHasKey($consumer, $factories);
        self::assertArrayNotHasKey($dependency, $factories);
        self::assertArrayHasKey(ClassWithoutDependencies::class, $factories);
    }

    /**
     * @return array{class-string, string}
     */
    private function fixture(bool $evaluated, ?string $dependency = null): array
    {
        $name = 'Unstampable' . bin2hex(random_bytes(6));
        $constructor = $dependency === null ? '' : 'public function __construct(public \\' . $dependency . ' $dependency) {}';
        $code = 'namespace GacelaTest\\Tmp; final class ' . $name . ' extends \\stdClass {' . $constructor . '}';
        $file = $this->file($name . '.php');

        if ($evaluated) {
            eval($code);
        } else {
            file_put_contents($file, '<?php ' . $code);
            require $file;
        }

        /** @var class-string $class */
        $class = 'GacelaTest\\Tmp\\' . $name;

        return [$class, $file];
    }

    private function file(string $name): string
    {
        $file = $this->directory . '/' . $name;
        $this->files[] = $file;

        return $file;
    }
}
