<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use ErrorException;
use Gacela\Container\Container;
use Gacela\Container\Exception\ContainerException;
use GacelaTest\Fake\AbstractService;
use GacelaTest\Fake\ClassWithoutDependencies;
use PHPUnit\Framework\TestCase;

/**
 * What happens when things go wrong.
 *
 * Every failure here used to either be swallowed or surface as a raw PHP
 * error from deep inside the container. A caller should get a container
 * exception naming the problem, or nothing should claim to have succeeded.
 */
final class FailureModesTest extends TestCase
{
    private string $unwritable = '/nonexistent-directory-for-tests/out.php';

    public function test_writing_a_compiled_cache_to_an_unwritable_path_throws(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/could not be written/');

        (new Container())->writeCompiledCache([ClassWithoutDependencies::class], $this->unwritable);
    }

    public function test_writing_compiled_factories_to_an_unwritable_path_throws(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/could not be written/');

        (new Container())->writeCompiledFactories([ClassWithoutDependencies::class], $this->unwritable);
    }

    public function test_writing_to_a_directory_path_throws(): void
    {
        $this->expectException(ContainerException::class);

        (new Container())->writeCompiledFactories([ClassWithoutDependencies::class], sys_get_temp_dir());
    }

    public function test_a_failed_write_still_throws_under_a_custom_error_handler(): void
    {
        // Frameworks routinely install one, which defeats @-suppression. The
        // writability check has to happen before the write, not around it.
        set_error_handler(static function (int $severity, string $message): bool {
            throw new ErrorException($message, 0, $severity);
        });

        try {
            $this->expectException(ContainerException::class);

            (new Container())->writeCompiledCache([ClassWithoutDependencies::class], $this->unwritable);
        } finally {
            restore_error_handler();
        }
    }

    public function test_loading_a_missing_compiled_cache_throws(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/could not be read/');

        Container::loadCompiledCache('/no/such/compiled-cache.php');
    }

    public function test_loading_a_compiled_cache_that_is_not_an_array_throws(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'garbage') . '.php';
        file_put_contents($file, '<?php return "not an array";');

        try {
            $this->expectException(ContainerException::class);
            $this->expectExceptionMessageMatches('/did not return an array/');

            Container::loadCompiledCache($file);
        } finally {
            @unlink($file);
        }
    }

    public function test_a_valid_compiled_cache_still_round_trips(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'plans') . '.php';

        (new Container())->writeCompiledCache([ClassWithoutDependencies::class], $file);
        $plans = Container::loadCompiledCache($file);
        @unlink($file);

        self::assertArrayHasKey(ClassWithoutDependencies::class, $plans);
    }

    public function test_resolving_an_abstract_class_throws_a_container_exception(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/cannot be instantiated/');

        (new Container())->get(AbstractService::class);
    }

    public function test_making_an_abstract_class_throws_a_container_exception(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/cannot be instantiated/');

        (new Container())->make(AbstractService::class);
    }

    public function test_has_still_reports_an_abstract_class_as_unresolvable(): void
    {
        // The counterpart to the two above: has() already answered correctly,
        // it was get() that disagreed by fatalling.
        self::assertFalse((new Container())->has(AbstractService::class));
    }
}
