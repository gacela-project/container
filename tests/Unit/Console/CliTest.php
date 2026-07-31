<?php

declare(strict_types=1);

namespace GacelaTest\Unit\Console;

use Gacela\Container\Console\Cli;
use Gacela\Container\Container;
use PHPUnit\Framework\TestCase;

use function sys_get_temp_dir;

final class CliTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        Container::resetStaticCaches();

        $this->workspace = sys_get_temp_dir() . '/gacela-cli-' . uniqid();
        mkdir($this->workspace . '/src', 0o775, true);

        file_put_contents($this->workspace . '/src/Demo.php', <<<'PHP'
            <?php
            namespace CliDemo;
            class Leaf {}
            class Root { public function __construct(public Leaf $leaf) {} }
            class NeedsScalar { public function __construct(public string $dsn) {} }
            interface Ignored {}
            PHP);

        // Its own namespace, declared in its own directory and never autoloaded
        // by any other test: PHP cannot unload a class, so a name some earlier
        // test loaded would still satisfy class_exists() here.
        mkdir($this->workspace . '/unloadable', 0o775, true);
        file_put_contents($this->workspace . '/unloadable/Demo.php', <<<'PHP'
            <?php
            namespace CliNeverLoaded;
            class Orphan {}
            PHP);

        $this->writeConfig('gacela-container.php');
        $this->writeConfig('no-paths.php');
        $this->writeConfig('no-source.php');
    }

    protected function tearDown(): void
    {
        Container::resetStaticCaches();
        $this->deleteTree($this->workspace);
    }

    public function test_help_is_the_default_and_succeeds(): void
    {
        [$status, $out] = $this->cli([]);

        self::assertSame(Cli::EXIT_OK, $status);
        self::assertStringContainsString('gacela-container compile', $out);
    }

    public function test_an_unknown_command_fails_and_says_so(): void
    {
        [$status, , $err] = $this->cli(['nope']);

        self::assertSame(Cli::EXIT_FAILURE, $status);
        self::assertStringContainsString("Unknown command 'nope'", $err);
    }

    /**
     * A misspelled option that was ignored would write nothing and report
     * success, which is the failure this command exists to remove.
     */
    public function test_an_unknown_option_is_rejected_rather_than_ignored(): void
    {
        [$status, , $err] = $this->cli(['compile', '--factorys=x.php', $this->configFlag()]);

        self::assertSame(Cli::EXIT_FAILURE, $status);
        self::assertStringContainsString('--factorys', $err);
    }

    public function test_a_missing_config_explains_why_one_is_needed(): void
    {
        [$status, , $err] = $this->cli(['report', '--config=' . $this->workspace . '/absent.php']);

        self::assertSame(Cli::EXIT_FAILURE, $status);
        self::assertStringContainsString('build *your* container', $err);
    }

    public function test_report_lists_what_compiles_and_why_the_rest_does_not(): void
    {
        [$status, $out] = $this->cli(['report', $this->configFlag()]);

        self::assertSame(Cli::EXIT_OK, $status);
        self::assertStringContainsString('CliDemo\Leaf', $out);
        self::assertStringContainsString('CliDemo\Root', $out);
        self::assertStringContainsString('scalar-parameter', $out);
        self::assertStringContainsString('CliDemo\NeedsScalar', $out);
    }

    public function test_report_is_zero_exit_without_strict_even_when_classes_are_refused(): void
    {
        [$status] = $this->cli(['report', $this->configFlag()]);

        self::assertSame(Cli::EXIT_OK, $status);
    }

    public function test_strict_fails_when_a_class_was_refused(): void
    {
        [$status, , $err] = $this->cli(['report', '--strict', $this->configFlag()]);

        self::assertSame(Cli::EXIT_FAILURE, $status);
        self::assertStringContainsString('--strict', $err);
    }

    public function test_compile_writes_both_files_and_says_what_it_wrote(): void
    {
        [$status, $out] = $this->cli([
            'compile',
            '--plans=' . $this->workspace . '/var/plans.php',
            '--factories=' . $this->workspace . '/var/factories.php',
            '--stamp=deadbeef',
            $this->configFlag(),
        ]);

        self::assertSame(Cli::EXIT_OK, $status);
        self::assertFileExists($this->workspace . '/var/plans.php');
        self::assertFileExists($this->workspace . '/var/factories.php');
        self::assertStringContainsString('Wrote plans', $out);
        self::assertStringContainsString('1 refused', $out);
    }

    public function test_the_written_factories_are_loadable_and_build_the_class(): void
    {
        $file = $this->workspace . '/var/factories.php';

        $this->cli(['compile', '--factories=' . $file, '--stamp=deadbeef', $this->configFlag()]);

        $factories = Container::loadCompiledFactories($file, 'deadbeef');

        self::assertArrayHasKey('CliDemo\Root', $factories);
        self::assertInstanceOf('CliDemo\Root', $factories['CliDemo\Root']());
    }

    public function test_a_mismatched_stamp_discards_the_file(): void
    {
        $file = $this->workspace . '/var/factories.php';

        $this->cli(['compile', '--factories=' . $file, '--stamp=deadbeef', $this->configFlag()]);

        self::assertSame([], Container::loadCompiledFactories($file, 'a-different-build'));
    }

    public function test_compile_refuses_to_run_with_nothing_to_write(): void
    {
        [$status, , $err] = $this->cli(['compile', $this->configFlag('no-paths.php')]);

        self::assertSame(Cli::EXIT_FAILURE, $status);
        self::assertStringContainsString('--plans=FILE', $err);
    }

    public function test_a_config_without_a_container_is_rejected(): void
    {
        file_put_contents($this->workspace . '/broken.php', '<?php return ["source" => null];');

        [$status, , $err] = $this->cli(['report', '--config=' . $this->workspace . '/broken.php']);

        self::assertSame(Cli::EXIT_FAILURE, $status);
        self::assertStringContainsString("'container' key", $err);
    }

    /**
     * Discovery reads declarations off disk and planning needs them loaded, so
     * a missing autoloader otherwise reports a cheerful zero having done
     * nothing at all.
     */
    public function test_classes_that_cannot_be_loaded_are_called_out(): void
    {
        $this->writeConfig('no-autoloader.php', autoload: false);

        [$status, , $err] = $this->cli(['report', $this->configFlag('no-autoloader.php')]);

        self::assertSame(Cli::EXIT_OK, $status);
        self::assertStringContainsString('could be loaded, so nothing was', $err);
    }

    public function test_a_directory_source_can_come_from_the_command_line(): void
    {
        [$status, $out] = $this->cli([
            'report',
            '--source=' . $this->workspace . '/src',
            $this->configFlag('no-source.php'),
        ]);

        self::assertSame(Cli::EXIT_OK, $status);
        self::assertStringContainsString('CliDemo\Leaf', $out);
    }

    public function test_no_source_anywhere_is_an_error(): void
    {
        [$status, , $err] = $this->cli(['report', $this->configFlag('no-source.php')]);

        self::assertSame(Cli::EXIT_FAILURE, $status);
        self::assertStringContainsString('--source=', $err);
    }

    private function writeConfig(string $name, bool $autoload = true): void
    {
        $workspace = var_export($this->workspace, true);
        $directory = $name === 'no-autoloader.php' ? '/unloadable' : '/src';
        $source = $name === 'no-source.php'
            ? 'null'
            : "\\Gacela\\Container\\ClassSource::fromDirectory({$workspace} . '{$directory}')";

        $paths = $name === 'no-paths.php'
            ? ''
            : "'plans' => {$workspace} . '/var/plans.php', 'factories' => {$workspace} . '/var/factories.php',";

        $autoloader = $autoload
            ? "spl_autoload_register(static function (string \$class) use (\$dir): void {
                   // is_file() because every test registers one of these and none
                   // can be unregistered: by the time a later test runs, earlier
                   // workspaces have been deleted and their autoloader would
                   // otherwise fatal on require of a path that is gone.
                   \$file = \$dir . '/src/Demo.php';
                   if (str_starts_with(\$class, 'CliDemo\\\\') && is_file(\$file)) { require_once \$file; }
               });"
            : '';

        file_put_contents($this->workspace . '/' . $name, <<<PHP
            <?php
            \$dir = {$workspace};
            {$autoloader}
            return [
                'container' => static fn () => new \\Gacela\\Container\\Container(),
                'source' => {$source},
                {$paths}
            ];
            PHP);
    }

    private function configFlag(string $name = 'gacela-container.php'): string
    {
        return '--config=' . $this->workspace . '/' . $name;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function cli(array $arguments): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');

        $status = (new Cli($out, $err))->run(['gacela-container', ...$arguments]);

        rewind($out);
        rewind($err);

        return [$status, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    private function deleteTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->deleteTree($path) : unlink($path);
        }

        rmdir($directory);
    }
}
