<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\Exception\ContainerException;
use GacelaTest\Fake\ClassWithObjectDependencies;
use GacelaTest\Fake\Person;
use PHPUnit\Framework\TestCase;

use function chmod;
use function is_readable;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class CompiledCacheTest extends TestCase
{
    public function test_compile_returns_plans_for_resolved_classes(): void
    {
        $container = new Container();

        $plans = $container->compile([ClassWithObjectDependencies::class]);

        self::assertArrayHasKey(ClassWithObjectDependencies::class, $plans);
        self::assertArrayHasKey(Person::class, $plans);
        self::assertTrue($plans[ClassWithObjectDependencies::class]['instantiable']);
        self::assertSame(
            Person::class,
            $plans[ClassWithObjectDependencies::class]['params'][0]['type'],
        );
    }

    public function test_write_and_load_compiled_cache_round_trip(): void
    {
        $file = sys_get_temp_dir() . '/' . uniqid('gacela_compiled_', true) . '.php';

        try {
            $container = new Container();
            $container->writeCompiledCache([ClassWithObjectDependencies::class], $file);

            $plans = Container::loadCompiledCache($file);
            self::assertArrayHasKey(ClassWithObjectDependencies::class, $plans);

            $compiled = new Container([], [], $plans);
            $actual = $compiled->get(ClassWithObjectDependencies::class);

            self::assertInstanceOf(ClassWithObjectDependencies::class, $actual);
            self::assertInstanceOf(Person::class, $actual->person);
        } finally {
            @unlink($file);
        }
    }

    public function test_container_built_from_compiled_plans_uses_scalar_defaults(): void
    {
        $source = new Container();
        $plans = $source->compile([Person::class]);

        $compiled = new Container([], [], $plans);
        $person = $compiled->get(Person::class);

        self::assertInstanceOf(Person::class, $person);
        self::assertSame('', $person->name);
    }

    public function test_an_unreadable_compiled_cache_is_reported_as_unreadable(): void
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gacela-unreadable-cache-' . uniqid() . '.php';
        (new Container())->writeCompiledCache([Person::class], $file);
        chmod($file, 0o000);

        try {
            if (is_readable($file)) {
                self::markTestSkipped('chmod has no effect here (root, or a filesystem without permissions)');
            }

            $this->expectException(ContainerException::class);

            @Container::loadCompiledCache($file);
        } finally {
            chmod($file, 0o600);
            unlink($file);
        }
    }
}
