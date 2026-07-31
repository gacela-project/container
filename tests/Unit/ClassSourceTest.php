<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\ClassSource;
use Gacela\Container\Container;
use Gacela\Container\Exception\ContainerException;
use GacelaTest\Fake\ClassWithDependencyWithoutDependencies;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\PersonWithoutDefaultValues;
use PHPUnit\Framework\TestCase;

use function count;
use function in_array;

final class ClassSourceTest extends TestCase
{
    protected function setUp(): void
    {
        Container::resetStaticCaches();
    }

    protected function tearDown(): void
    {
        Container::resetStaticCaches();
    }

    public function test_a_list_source_compiles_the_same_classes_as_the_list(): void
    {
        $plans = (new Container())->compile(ClassSource::fromList([ClassWithDependencyWithoutDependencies::class]));

        self::assertArrayHasKey(ClassWithDependencyWithoutDependencies::class, $plans);
    }

    public function test_a_source_reaches_the_whole_constructor_graph(): void
    {
        // Level1's dependencies are never named, so anything below it in the
        // map got there by the plan walking the constructor.
        $plans = (new Container())->compile(ClassSource::fromList([ClassWithDependencyWithoutDependencies::class]));

        self::assertGreaterThan(1, count($plans));
    }

    /**
     * The reason discovery cannot go through warmUp(): resolving this class
     * throws, and a classmap is full of classes nothing can supply.
     */
    public function test_a_discovered_class_that_cannot_be_resolved_is_planned_not_resolved(): void
    {
        $plans = (new Container())->compile(ClassSource::fromList([PersonWithoutDefaultValues::class]));

        self::assertArrayHasKey(PersonWithoutDefaultValues::class, $plans);
    }

    public function test_the_same_class_through_a_list_would_have_thrown(): void
    {
        // Locks the asymmetry above in place, so the two paths cannot quietly
        // converge without this failing.
        $this->expectException(\Gacela\Container\Exception\DependencyInvalidArgumentException::class);

        (new Container())->compile([PersonWithoutDefaultValues::class]);
    }

    public function test_a_discovered_class_never_runs_a_constructor(): void
    {
        ConstructionSpy::$built = 0;

        (new Container())->compile(ClassSource::fromList([HoldsAConstructionSpy::class]));

        self::assertSame(0, ConstructionSpy::$built);
    }

    public function test_a_listed_class_does_run_its_dependencies_constructors(): void
    {
        ConstructionSpy::$built = 0;

        (new Container())->compile([HoldsAConstructionSpy::class]);

        self::assertSame(1, ConstructionSpy::$built);
    }

    public function test_a_directory_source_finds_declared_classes(): void
    {
        $found = ClassSource::fromDirectory(__DIR__ . '/../Fake')->classNames();

        self::assertTrue(in_array(ClassWithoutDependencies::class, $found, true));
    }

    public function test_a_directory_source_skips_interfaces_and_traits(): void
    {
        $found = ClassSource::fromDirectory(__DIR__ . '/../Fake')->classNames();

        self::assertFalse(in_array(\GacelaTest\Fake\PersonInterface::class, $found, true));
        self::assertFalse(in_array(\GacelaTest\Fake\RepositoryInterface::class, $found, true));
    }

    public function test_a_directory_source_is_scanned_once(): void
    {
        $source = ClassSource::fromDirectory(__DIR__ . '/../Fake');

        self::assertSame($source->classNames(), $source->classNames());
    }

    public function test_an_unknown_directory_is_an_error_naming_it(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/nope-does-not-exist/');

        ClassSource::fromDirectory('/tmp/nope-does-not-exist')->classNames();
    }

    public function test_an_unreadable_classmap_is_an_error_naming_the_file(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/not-a-classmap/');

        ClassSource::fromComposerClassmap('/tmp/not-a-classmap.php')->classNames();
    }

    public function test_a_classmap_file_is_read_as_class_names(): void
    {
        // Written here rather than read from vendor/: whether this project's own
        // classes appear in the real map depends on --optimize-autoloader, which
        // is not something a test should depend on.
        $file = sys_get_temp_dir() . '/gacela-classmap-' . uniqid() . '.php';
        file_put_contents($file, '<?php return ' . var_export([
            ClassWithoutDependencies::class => '/some/file.php',
            ClassWithDependencyWithoutDependencies::class => '/another/file.php',
        ], true) . ';');

        try {
            $found = ClassSource::fromComposerClassmap($file)->classNames();
        } finally {
            unlink($file);
        }

        self::assertSame([
            ClassWithoutDependencies::class,
            ClassWithDependencyWithoutDependencies::class,
        ], $found);
    }

    public function test_the_classmap_of_this_installation_is_located_without_being_named(): void
    {
        // Only that the walk up to vendor/ finds a readable map; what is in it
        // depends on how the project was installed.
        self::assertNotSame([], ClassSource::fromComposerClassmap()->classNames());
    }

    public function test_a_report_can_be_asked_of_a_discovered_set(): void
    {
        $report = (new Container())->compileReport(ClassSource::fromList([ClassWithDependencyWithoutDependencies::class]));

        self::assertContains(ClassWithDependencyWithoutDependencies::class, $report->compiled());
    }
}

final class ConstructionSpy
{
    public static int $built = 0;

    public function __construct()
    {
        ++self::$built;
    }
}

final class HoldsAConstructionSpy
{
    public function __construct(public ConstructionSpy $spy)
    {
    }
}
