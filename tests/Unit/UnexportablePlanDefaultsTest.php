<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use ArrayObject;
use Gacela\Container\Attribute\Inject;
use Gacela\Container\Container;
use Gacela\Container\PlanCache;
use GacelaTest\Fake\Person;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class UnexportablePlanDefaultsTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        Container::resetStaticCaches();
        $this->file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('unexportable-plan-', true) . '.php';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        Container::resetStaticCaches();
    }

    /**
     * @return iterable<string, array{class-string, bool}>
     */
    public static function unexportablePlans(): iterable
    {
        foreach ([false, true] as $shared) {
            $writer = $shared ? 'shared plan cache' : 'container writer';
            yield $writer . ' with object constructor default' => [ObjectConstructorCacheDefault::class, $shared];
            yield $writer . ' with internal object constructor default' => [InternalObjectConstructorCacheDefault::class, $shared];
            yield $writer . ' with object nested in constructor array' => [NestedObjectConstructorCacheDefault::class, $shared];
            yield $writer . ' with object method default' => [ObjectMethodCacheDefault::class, $shared];
            yield $writer . ' with object nested in method array' => [NestedObjectMethodCacheDefault::class, $shared];
            yield $writer . ' with object supporting set_state' => [RestorableObjectConstructorCacheDefault::class, $shared];
            if (PHP_VERSION_ID >= 80500) {
                yield $writer . ' with closure constructor default' => [ClosureConstructorCacheDefault::class, $shared];
                yield $writer . ' with closure method default' => [ClosureMethodCacheDefault::class, $shared];
            }
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('unexportablePlans')]
    public function test_plan_writers_omit_object_defaults_and_the_next_container_uses_reflection(string $class, bool $shared): void
    {
        $this->writePlans([$class, Person::class], $shared);

        $plans = Container::loadCompiledCache($this->file);
        self::assertArrayNotHasKey($class, $plans);
        self::assertArrayHasKey(Person::class, $plans);
        $container = new Container([], [], $plans);
        $service = $container->get($class);
        self::assertInstanceOf($class, $service);
        self::assertNotNull($service->value);
        self::assertSame('', $container->get(Person::class)->name);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function writers(): iterable
    {
        yield 'container writer' => [false];
        yield 'shared plan cache' => [true];
    }

    #[DataProvider('writers')]
    public function test_enum_scalar_and_nested_array_defaults_survive_both_writer_paths(bool $shared): void
    {
        $this->writePlans([ExportableCacheDefaults::class], $shared);

        $plans = Container::loadCompiledCache($this->file);
        self::assertArrayHasKey(ExportableCacheDefaults::class, $plans);
        $service = (new Container([], [], $plans))->get(ExportableCacheDefaults::class);
        self::assertSame([
            'nested' => [CacheUnitDefault::Automatic, CacheBackedDefault::Fast, null, true, 42, 1.5, 'value'],
        ], $service->value);
        self::assertSame(CacheUnitDefault::Automatic, $service->methodValue);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function failedReads(): iterable
    {
        yield 'truncated PHP' => ['<?php return ['];
        yield 'old object export' => ['<?php return ["plans" => [\\' . stdClass::class . '::__set_state([])]];'];
        yield 'exception during include' => ['<?php throw new \\RuntimeException("invalid cache");'];
        yield 'error during include' => ['<?php throw new \\Error("invalid cache");'];
    }

    #[DataProvider('failedReads')]
    public function test_shared_plan_cache_returns_empty_after_any_read_failure(string $code): void
    {
        file_put_contents($this->file, $code);
        chmod($this->file, 0o600);

        $restored = PlanCache::fromFile($this->file);
        self::assertSame([], $restored->classes());
        self::assertSame(0, $restored->count());
        self::assertSame('', (new Container([], [], [], $restored))->get(Person::class)->name);
    }

    /**
     * @param list<class-string> $classes
     */
    private function writePlans(array $classes, bool $shared): void
    {
        if (!$shared) {
            (new Container())->writeCompiledCache($classes, $this->file);

            return;
        }

        $cache = new PlanCache();
        $container = new Container([], [], [], $cache);
        foreach ($classes as $class) {
            $container->get($class);
        }

        $cache->writeTo($this->file);
    }
}

final class CacheDefaultToken
{
}

final class ObjectConstructorCacheDefault
{
    public function __construct(public CacheDefaultToken $value = new CacheDefaultToken())
    {
    }
}

final class InternalObjectConstructorCacheDefault
{
    public function __construct(public ArrayObject $value = new ArrayObject())
    {
    }
}

final class NestedObjectConstructorCacheDefault
{
    public function __construct(public array $value = ['nested' => [new CacheDefaultToken()]])
    {
    }
}

final class ObjectMethodCacheDefault
{
    public CacheDefaultToken $value;

    #[Inject]
    public function configure(CacheDefaultToken $value = new CacheDefaultToken()): void
    {
        $this->value = $value;
    }
}

final class NestedObjectMethodCacheDefault
{
    public array $value;

    #[Inject]
    public function configure(array $value = ['nested' => [new CacheDefaultToken()]]): void
    {
        $this->value = $value;
    }
}

final class RestorableCacheDefaultToken
{
    public static function __set_state(array $state): self
    {
        return new self();
    }
}

final class RestorableObjectConstructorCacheDefault
{
    public function __construct(public RestorableCacheDefaultToken $value = new RestorableCacheDefaultToken())
    {
    }
}

enum CacheUnitDefault
{
    case Automatic;
}

enum CacheBackedDefault: string
{
    case Fast = 'fast';
}

final class ExportableCacheDefaults
{
    public CacheUnitDefault $methodValue;

    public function __construct(public array $value = ['nested' => [CacheUnitDefault::Automatic, CacheBackedDefault::Fast, null, true, 42, 1.5, 'value']])
    {
    }

    #[Inject]
    public function configure(CacheUnitDefault $value = CacheUnitDefault::Automatic): void
    {
        $this->methodValue = $value;
    }
}

if (PHP_VERSION_ID >= 80500) {
    eval(<<<'PHP'
namespace GacelaTest\Unit;

final class ClosureConstructorCacheDefault
{
    public function __construct(public \Closure $value = static function () {})
    {
    }
}

final class ClosureMethodCacheDefault
{
    public \Closure $value;

    #[\Gacela\Container\Attribute\Inject]
    public function configure(\Closure $value = static function () {}): void
    {
        $this->value = $value;
    }
}
PHP);
}
