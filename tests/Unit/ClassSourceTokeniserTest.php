<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\ClassSource;
use Gacela\Container\Exception\ContainerException;
use PHPUnit\Framework\TestCase;

use function in_array;
use function sys_get_temp_dir;

/**
 * What `fromDirectory()` actually reads off disk.
 *
 * It tokenises rather than loading, so a file that declares nothing is never
 * included for whatever it does at the top level — which means the tokeniser
 * has to tell a declaration from every other place the word `class` appears.
 */
final class ClassSourceTokeniserTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gacela-src-' . uniqid();
        mkdir($this->dir . '/nested', 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,nested/}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }

        @rmdir($this->dir . '/nested');
        @rmdir($this->dir);
    }

    public function test_a_namespaced_class_is_fully_qualified(): void
    {
        $this->write('A.php', "namespace App\\Domain;\nclass Thing {}\n");

        self::assertSame(['App\Domain\Thing'], $this->found());
    }

    public function test_a_class_with_no_namespace_keeps_its_bare_name(): void
    {
        $this->write('A.php', "class Bare {}\n");

        self::assertSame(['Bare'], $this->found());
    }

    public function test_several_classes_in_one_file_are_all_found(): void
    {
        $this->write('A.php', "namespace App;\nclass One {}\nclass Two {}\nclass Three {}\n");

        self::assertSame(['App\One', 'App\Two', 'App\Three'], $this->found());
    }

    public function test_interfaces_traits_and_enums_are_left_out(): void
    {
        $this->write('A.php', "namespace App;\ninterface Ifc {}\ntrait T {}\nenum E {}\nclass Real {}\n");

        self::assertSame(['App\Real'], $this->found());
    }

    /**
     * An abstract reads as a class, and the compiler refusing it by name is
     * useful — so unlike the three above it is kept.
     */
    public function test_an_abstract_class_is_kept(): void
    {
        $this->write('A.php', "namespace App;\nabstract class Base {}\n");

        self::assertSame(['App\Base'], $this->found());
    }

    /**
     * `Foo::class` is a T_CLASS token too. Without the `::` check it would be
     * read as a declaration of whatever followed.
     */
    public function test_a_class_constant_reference_is_not_a_declaration(): void
    {
        $this->write('A.php', "namespace App;\nclass Real { public function f(): string { return Other::class; } }\n");

        self::assertSame(['App\Real'], $this->found());
    }

    public function test_an_anonymous_class_is_not_a_declaration(): void
    {
        $this->write('A.php', "namespace App;\n\$x = new class {};\nclass Real {}\n");

        self::assertSame(['App\Real'], $this->found());
    }

    public function test_a_file_declaring_nothing_contributes_nothing(): void
    {
        $this->write('A.php', "namespace App;\n\$config = ['a' => 1];\n");

        self::assertSame([], $this->found());
    }

    public function test_nested_directories_are_scanned(): void
    {
        $this->write('A.php', "namespace App;\nclass Top {}\n");
        file_put_contents($this->dir . '/nested/B.php', "<?php\nnamespace App\\Deep;\nclass Down {}\n");

        $found = $this->found();

        self::assertTrue(in_array('App\Top', $found, true));
        self::assertTrue(in_array('App\Deep\Down', $found, true));
    }

    public function test_non_php_files_are_ignored(): void
    {
        $this->write('A.php', "namespace App;\nclass Real {}\n");
        file_put_contents($this->dir . '/notes.txt', 'class NotReal {}');

        self::assertSame(['App\Real'], $this->found());
    }

    public function test_a_class_extending_another_is_read_by_its_own_name(): void
    {
        $this->write('A.php', "namespace App;\nclass Child extends \\App\\Parented implements \\Countable {}\n");

        self::assertSame(['App\Child'], $this->found());
    }

    public function test_a_duplicate_declaration_across_files_is_listed_once(): void
    {
        $this->write('A.php', "namespace App;\nclass Same {}\n");
        file_put_contents($this->dir . '/nested/B.php', "<?php\nnamespace App;\nclass Same {}\n");

        self::assertSame(['App\Same'], $this->found());
    }

    /**
     * Discovery is memoized, so passing one source to several compile calls
     * scans the tree once.
     */
    public function test_the_scan_result_is_reused(): void
    {
        $this->write('A.php', "namespace App;\nclass Real {}\n");

        $source = ClassSource::fromDirectory($this->dir);
        $first = $source->classNames();

        // Written after the first scan: a second scan would pick it up.
        $this->write('B.php', "namespace App;\nclass Later {}\n");

        self::assertSame($first, $source->classNames());
    }

    public function test_a_classmap_that_is_not_an_array_is_refused(): void
    {
        $file = $this->dir . '/map.php';
        file_put_contents($file, '<?php return "not a map";');

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/map\.php/');

        ClassSource::fromComposerClassmap($file)->classNames();
    }

    public function test_several_directories_are_merged(): void
    {
        $this->write('A.php', "namespace App;\nclass One {}\n");
        file_put_contents($this->dir . '/nested/B.php', "<?php\nnamespace App;\nclass Two {}\n");

        $found = ClassSource::fromDirectory($this->dir . '/nested', $this->dir)->classNames();

        self::assertTrue(in_array('App\One', $found, true));
        self::assertTrue(in_array('App\Two', $found, true));
    }

    private function write(string $name, string $php): void
    {
        file_put_contents($this->dir . '/' . $name, "<?php\n" . $php);
    }

    /**
     * @return list<string>
     */
    private function found(): array
    {
        return ClassSource::fromDirectory($this->dir)->classNames();
    }
}
