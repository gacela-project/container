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

    /**
     * A namespace of its own per test. PHP cannot unload a class and cannot
     * declare one twice, so a shared name would either be answered by an
     * earlier test's (now deleted) workspace or redeclared from a second file.
     */
    private string $namespace;

    protected function setUp(): void
    {
        Container::resetStaticCaches();

        $id = uniqid();
        $this->workspace = sys_get_temp_dir() . '/gacela-cli-' . $id;
        $this->namespace = 'CliDemo' . $id;
        mkdir($this->workspace . '/src', 0o775, true);

        file_put_contents($this->workspace . '/src/Demo.php', <<<PHP
            <?php
            namespace {$this->namespace};
            class Leaf {}
            class Root { public function __construct(public Leaf \$leaf) {} }
            class NeedsScalar { public function __construct(public string \$dsn) {} }
            interface Ignored {}
            PHP);

        // Its own namespace, declared in its own directory and never autoloaded
        // by any other test: PHP cannot unload a class, so a name some earlier
        // test loaded would still satisfy class_exists() here.
        mkdir($this->workspace . '/unloadable', 0o775, true);
        file_put_contents($this->workspace . '/unloadable/Demo.php', <<<PHP
            <?php
            namespace Unloadable{$this->namespace};
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

    /**
     * `--help`, `-h` and `help` are three spellings of one command, and
     * `--version` is its own; each is a match arm that can be dropped without
     * any other test noticing.
     */
    public function test_every_spelling_of_help_prints_the_usage(): void
    {
        foreach (['help', '--help', '-h'] as $spelling) {
            [$status, $out] = $this->cli([$spelling]);

            self::assertSame(Cli::EXIT_OK, $status, $spelling);
            self::assertStringContainsString('gacela-container compile', $out, $spelling);
        }
    }

    public function test_version_is_its_own_command(): void
    {
        foreach (['--version', '-V'] as $spelling) {
            [$status, $out] = $this->cli([$spelling]);

            self::assertSame(Cli::EXIT_OK, $status, $spelling);
            self::assertStringContainsString('gacela-project/container', $out, $spelling);
        }
    }

    /**
     * The paths come from the config when no flag gives them, which is the
     * whole point of having a config: CI and a shell run the same thing.
     */
    public function test_the_config_supplies_the_paths_when_no_flag_does(): void
    {
        [$status, $out] = $this->cli(['compile', $this->configFlag()]);

        self::assertSame(Cli::EXIT_OK, $status);
        self::assertFileExists($this->workspace . '/var/plans.php');
        self::assertFileExists($this->workspace . '/var/factories.php');
        self::assertStringContainsString('Wrote plans', $out);
    }

    public function test_a_flag_overrides_the_configured_path(): void
    {
        $elsewhere = $this->workspace . '/elsewhere/plans.php';

        $this->cli(['compile', '--plans=' . $elsewhere, $this->configFlag()]);

        self::assertFileExists($elsewhere);
    }

    public function test_plans_alone_writes_no_factories(): void
    {
        [$status] = $this->cli([
            'compile',
            '--plans=' . $this->workspace . '/only/plans.php',
            $this->configFlag('no-paths.php'),
        ]);

        self::assertSame(Cli::EXIT_OK, $status);
        self::assertFileExists($this->workspace . '/only/plans.php');
        self::assertFileDoesNotExist($this->workspace . '/only/factories.php');
    }

    public function test_factories_alone_writes_no_plans(): void
    {
        [$status] = $this->cli([
            'compile',
            '--factories=' . $this->workspace . '/only/factories.php',
            $this->configFlag('no-paths.php'),
        ]);

        self::assertSame(Cli::EXIT_OK, $status);
        self::assertFileExists($this->workspace . '/only/factories.php');
        self::assertFileDoesNotExist($this->workspace . '/only/plans.php');
    }

    /**
     * Omitting the stamp is legal and the slower of the two validations, so the
     * command says which one is in force rather than leaving it to be inferred.
     */
    public function test_omitting_the_stamp_is_said_out_loud(): void
    {
        [, $out] = $this->cli(['compile', $this->configFlag()]);

        self::assertStringContainsString('No --stamp given', $out);
    }

    public function test_giving_a_stamp_says_nothing_about_mtimes(): void
    {
        [, $out] = $this->cli(['compile', '--stamp=abc', $this->configFlag()]);

        self::assertStringNotContainsString('No --stamp given', $out);
    }

    public function test_report_counts_the_discovered_classes(): void
    {
        [, $out] = $this->cli(['report', $this->configFlag()]);

        self::assertStringContainsString('Discovered 3 class(es).', $out);
    }

    /**
     * The whole of what `report` prints, so a line that stops being written is
     * a failure here rather than a quietly shorter report.
     */
    public function test_report_prints_a_tally_and_both_sections(): void
    {
        [, $out] = $this->cli(['report', $this->configFlag()]);

        self::assertStringContainsString('2 compiled, 1 refused.', $out);
        self::assertStringContainsString('Compiled:', $out);
        self::assertStringContainsString('Refused:', $out);
    }

    public function test_compile_counts_the_discovered_classes_too(): void
    {
        [, $out] = $this->cli(['compile', $this->configFlag()]);

        self::assertStringContainsString('Discovered 3 class(es).', $out);
    }

    public function test_compile_says_how_many_factories_it_wrote_and_refused(): void
    {
        [, $out] = $this->cli([
            'compile',
            '--factories=' . $this->workspace . '/var/factories.php',
            $this->configFlag('no-paths.php'),
        ]);

        self::assertStringContainsString('Wrote 2 factory/factories to', $out);
        self::assertStringContainsString('(1 refused)', $out);
    }

    public function test_a_source_with_nothing_refused_prints_no_refused_section(): void
    {
        mkdir($this->workspace . '/clean2', 0o775, true);
        file_put_contents($this->workspace . '/clean2/Clean.php', <<<PHP
            <?php
            namespace {$this->namespace};
            class OnlyFine {}
            PHP);

        [, $out] = $this->cli([
            'report',
            '--source=' . $this->workspace . '/clean2',
            $this->configFlag('no-source.php'),
        ]);

        self::assertStringContainsString('1 compiled, 0 refused.', $out);
        self::assertStringNotContainsString('Refused:', $out);
    }

    /**
     * --strict only fails when something was actually refused; a clean run has
     * to stay green or it is useless in a build.
     */
    public function test_strict_passes_when_everything_compiled(): void
    {
        mkdir($this->workspace . '/clean', 0o775, true);
        file_put_contents($this->workspace . '/clean/Clean.php', <<<PHP
            <?php
            namespace {$this->namespace};
            class AlsoFine {}
            PHP);

        [$status] = $this->cli([
            'report',
            '--strict',
            '--source=' . $this->workspace . '/clean',
            $this->configFlag('no-source.php'),
        ]);

        self::assertSame(Cli::EXIT_OK, $status);
    }

    public function test_the_stamp_can_come_from_the_config(): void
    {
        file_put_contents($this->workspace . '/stamped.php', <<<PHP
            <?php
            \$dir = {$this->exportedWorkspace()};
            spl_autoload_register(static function (string \$class) use (\$dir): void {
                \$file = \$dir . '/src/Demo.php';
                if (str_starts_with(\$class, '{$this->namespace}\\\\') && is_file(\$file)) { require_once \$file; }
            });
            return [
                'container' => static fn () => new \\Gacela\\Container\\Container(),
                'source' => \\Gacela\\Container\\ClassSource::fromDirectory(\$dir . '/src'),
                'factories' => \$dir . '/var/factories.php',
                'stamp' => 'from-config',
            ];
            PHP);

        [, $out] = $this->cli(['compile', $this->configFlag('stamped.php')]);

        self::assertStringNotContainsString('No --stamp given', $out);
        self::assertNotSame(
            [],
            Container::loadCompiledFactories($this->workspace . '/var/factories.php', 'from-config'),
        );
    }

    /**
     * The value is everything past the first `=`, so a value containing one
     * survives intact.
     */
    public function test_an_option_value_may_contain_an_equals_sign(): void
    {
        $this->cli([
            'compile',
            '--factories=' . $this->workspace . '/var/factories.php',
            '--stamp=build=17',
            $this->configFlag(),
        ]);

        self::assertNotSame(
            [],
            Container::loadCompiledFactories($this->workspace . '/var/factories.php', 'build=17'),
        );
    }

    public function test_a_config_whose_source_is_not_a_class_source_is_rejected(): void
    {
        file_put_contents($this->workspace . '/bad-source.php', <<<'PHP'
            <?php
            return [
                'container' => static fn () => new \Gacela\Container\Container(),
                'source' => 'src/',
            ];
            PHP);

        [$status, , $err] = $this->cli(['report', $this->configFlag('bad-source.php')]);

        self::assertSame(Cli::EXIT_FAILURE, $status);
        self::assertStringContainsString("'source' key", $err);
    }

    /**
     * Nothing found is not the same as nothing loadable, so it must not trip
     * the autoloader warning.
     */
    public function test_an_empty_source_is_not_reported_as_an_autoloader_problem(): void
    {
        mkdir($this->workspace . '/empty', 0o775, true);

        [$status, $out, $err] = $this->cli([
            'report',
            '--source=' . $this->workspace . '/empty',
            $this->configFlag('no-source.php'),
        ]);

        self::assertSame(Cli::EXIT_OK, $status);
        self::assertStringContainsString('Discovered 0 class(es).', $out);
        self::assertStringNotContainsString('could be loaded', $err);
    }

    public function test_validate_passes_on_a_resolvable_set(): void
    {
        mkdir($this->workspace . '/clean3', 0o775, true);
        file_put_contents($this->workspace . '/clean3/Clean.php', <<<PHP
            <?php
            namespace {$this->namespace};
            class Fine {}
            PHP);

        [$status, $out] = $this->cli([
            'validate',
            '--source=' . $this->workspace . '/clean3',
            $this->configFlag('no-source.php'),
        ]);

        self::assertSame(Cli::EXIT_OK, $status);
        self::assertStringContainsString('no problems found', $out);
    }

    /**
     * validate fails by default, unlike report: an unresolvable service is a
     * broken build rather than a missed optimisation.
     */
    public function test_validate_fails_on_an_unresolvable_service(): void
    {
        [$status, $out] = $this->cli(['validate', $this->configFlag()]);

        self::assertSame(Cli::EXIT_FAILURE, $status);
        self::assertStringContainsString('unresolvable-parameter', $out);
        self::assertStringContainsString('NeedsScalar', $out);
    }

    public function test_validate_is_listed_in_the_usage(): void
    {
        [, $out] = $this->cli(['help']);

        self::assertStringContainsString('validate', $out);
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
        self::assertStringContainsString($this->namespace . '\\Leaf', $out);
        self::assertStringContainsString($this->namespace . '\\Root', $out);
        self::assertStringContainsString('scalar-parameter', $out);
        self::assertStringContainsString($this->namespace . '\\NeedsScalar', $out);
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

        self::assertArrayHasKey($this->namespace . '\\Root', $factories);
        self::assertInstanceOf($this->namespace . '\\Root', $factories[$this->namespace . '\\Root']());
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
        self::assertStringContainsString($this->namespace . '\\Leaf', $out);
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
                   // Every fixture directory, because a test may add one; and
                   // is_file()/glob() because none of these registrations can be
                   // unregistered, so by the time a later test runs the earlier
                   // workspaces are gone and a bare require would fatal.
                   // 'unloadable' is excluded on purpose -- one test needs a
                   // class that cannot be loaded.
                   if (!str_starts_with(\$class, '{$this->namespace}\\\\')) { return; }
                   foreach (glob(\$dir . '/{src,clean,clean2,clean3}/*.php', GLOB_BRACE) ?: [] as \$file) {
                       require_once \$file;
                   }
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

    private function exportedWorkspace(): string
    {
        return var_export($this->workspace, true);
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
