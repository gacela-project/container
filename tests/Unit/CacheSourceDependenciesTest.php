<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function dirname;

final class CacheSourceDependenciesTest extends TestCase
{
    private string $directory;

    /** @var array<string, true> */
    private array $files = [];

    private int $processes = 0;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/container-source-dependencies-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o700);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file => $_) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        rmdir($this->directory);
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function planSources(): iterable
    {
        foreach (['parent constructor', 'nested trait constructor', 'parent property', 'trait property', 'private trait property', 'parent method', 'trait method', 'interface marker', 'overridden parent marker'] as $source) {
            foreach (['plans', 'shared plans'] as $kind) {
                foreach ([false, true] as $deleted) {
                    yield $kind . ' ' . $source . ($deleted ? ' deleted' : ' changed') => [$source, $kind, $deleted];
                }
            }
        }
    }

    #[DataProvider('planSources')]
    public function test_changed_plan_sources_are_rejected_in_the_next_process(string $source, string $kind, bool $deleted): void
    {
        [$changed, $replacement, $value] = $this->fixture($source);
        $this->writeCache($kind);
        $this->change($changed, $replacement, $deleted);

        $result = $this->readCache($kind, $deleted ? 'null' : $value, !$deleted);

        self::assertNotContains('CacheSourceFixture\\Entry', $result['entries']);
        if ($kind === 'shared plans') {
            self::assertNotContains('CacheSourceFixture\\Unchanged', $result['entries']);
        } else {
            self::assertContains('CacheSourceFixture\\Unchanged', $result['entries']);
        }
        self::assertFalse($result['autoloaded']);
        self::assertSame(99, $result['unchanged']);
        if (!$deleted) {
            self::assertSame(str_contains($source, 'marker') ? null : 23, $result['value']);
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function factorySources(): iterable
    {
        foreach (['direct dependency', 'deep dependency', 'inherited dependency'] as $source) {
            foreach ([false, true] as $deleted) {
                yield $source . ($deleted ? ' deleted' : ' changed') => [$source, $deleted];
            }
        }
    }

    #[DataProvider('factorySources')]
    public function test_every_inlined_factory_source_invalidates_its_consumers(string $source, bool $deleted): void
    {
        [$changed, $replacement, $value] = $this->fixture($source);
        $this->writeCache('factories');
        $this->change($changed, $replacement, $deleted);
        $result = $this->readCache('factories', $deleted ? 'null' : $value, !$deleted);

        self::assertNotContains('CacheSourceFixture\\Entry', $result['entries']);
        self::assertNotContains('CacheSourceFixture\\Changed', $result['entries']);
        if ($source === 'deep dependency') {
            self::assertNotContains('CacheSourceFixture\\Middle', $result['entries']);
        }
        self::assertContains('CacheSourceFixture\\Leaf', $result['entries']);
        self::assertContains('CacheSourceFixture\\Unchanged', $result['entries']);
        self::assertFalse($result['autoloaded']);
        self::assertSame(99, $result['unchanged']);
        if (!$deleted) {
            self::assertSame(23, $result['value']);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function cacheKinds(): iterable
    {
        foreach (['plans', 'shared plans', 'factories'] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('cacheKinds')]
    public function test_matching_build_stamps_bypass_dependency_file_checks(string $kind): void
    {
        [$changed, $replacement] = $this->fixture($kind === 'factories' ? 'inherited dependency' : 'parent constructor');
        $this->writeCache($kind, 'deployment-1');
        $this->change($changed, $replacement, true);
        $loader = $this->loader($kind);
        $result = $this->process(<<<PHP
\$matching = {$loader}('deployment-1');
\$different = {$loader}('deployment-2');
\$unstamped = {$loader}(null);
\$result = ['matching' => array_keys(\$matching), 'different' => array_keys(\$different), 'unstamped' => array_keys(\$unstamped)];
PHP);

        self::assertContains('CacheSourceFixture\\Entry', $result['matching']);
        self::assertSame([], $result['different']);
        self::assertNotContains('CacheSourceFixture\\Entry', $result['unstamped']);
        if ($kind === 'shared plans') {
            self::assertSame([], $result['unstamped']);
        } else {
            self::assertContains('CacheSourceFixture\\Unchanged', $result['unstamped']);
        }
    }

    public function test_a_new_plan_cache_cannot_vouch_for_preloaded_replaced_parent_sources(): void
    {
        [$changed, $replacement, $value] = $this->fixture('parent constructor');
        $origin = var_export($this->path($changed . '.php'), true);
        $code = var_export($this->sourceCode($replacement), true);
        $file = var_export($this->path('cache.php'), true);
        $loader = $this->loader('shared plans');
        $result = $this->process(<<<PHP
class_exists(\\CacheSourceFixture\\Entry::class);
file_put_contents({$origin}, {$code});
touch({$origin}, time() - 60);
clearstatcache();
\$cache = new \\Gacela\\Container\\PlanCache();
\$container = new \\Gacela\\Container\\Container([], [], [], \$cache);
\$container->compile([\\CacheSourceFixture\\Entry::class, \\CacheSourceFixture\\Unchanged::class]);
\$cache->writeTo({$file});
\$result = ['entries' => array_keys({$loader}(null))];
PHP);

        self::assertNotContains('CacheSourceFixture\\Entry', $result['entries']);
        self::assertSame([], $result['entries']);
        $resolved = $this->readCache('shared plans', $value, true);
        self::assertSame(23, $resolved['value']);
    }

    /**
     * @return array{string, string, string}
     */
    private function fixture(string $source): array
    {
        $this->source('Leaf', 'final class Leaf { public int $value = 7; }');
        $this->source('ExtraLeaf', 'final class ExtraLeaf { public int $value = 23; }');
        $this->source('Unchanged', 'final class Unchanged { public int $value = 99; }');

        if (str_contains($source, 'dependency')) {
            $before = 'public function __construct(public Leaf $dependency) {}';
            $after = 'public function __construct(public Leaf $dependency, public ExtraLeaf $extra) {}';
            $origin = $source === 'inherited dependency' ? 'Origin' : 'Changed';
            $this->source($origin, "class {$origin} { {$before} }");
            if ($origin === 'Origin') {
                $this->source('Changed', 'final class Changed extends Origin {}');
            }
            $dependency = 'Changed';
            $value = '$entry->dependency->extra->value';
            if ($source === 'deep dependency') {
                $this->source('Middle', 'final class Middle { public function __construct(public Changed $dependency) {} }');
                $dependency = 'Middle';
                $value = '$entry->dependency->dependency->extra->value';
            }
            $this->source('Entry', "final class Entry { public function __construct(public {$dependency} \$dependency) {} }");

            return [$origin, "class {$origin} { {$after} }", $value];
        }

        if ($source === 'interface marker') {
            $before = 'interface Origin { #[Inject] public function configure(Leaf $dependency): void; }';
            $this->source('Origin', $before);
            $this->source('LeftMarker', 'interface LeftMarker extends Origin {}');
            $this->source('RightMarker', 'interface RightMarker extends Origin { public function configure(Leaf $renamed): void; }');
            $this->source('Implementation', 'class Implementation { public ?object $injected = null; public function configure(Leaf $dependency): void { $this->injected = $dependency; } }');
            $this->source('Entry', 'final class Entry extends Implementation implements LeftMarker, RightMarker {}');

            return ['Origin', str_replace('#[Inject]', '', $before), '$entry->injected?->value'];
        }

        $before = match ($source) {
            'parent constructor', 'nested trait constructor' => 'public function __construct(public Leaf $dependency) {}',
            'parent property', 'trait property' => '#[Inject] public Leaf $injected;',
            'private trait property' => '#[Inject] private Leaf $injected; public function injected(): object { return $this->injected; }',
            default => 'public ?object $injected = null; #[Inject] public function configure(Leaf $dependency): void { $this->injected = $dependency; }',
        };
        $after = str_replace('Leaf', 'ExtraLeaf', $before);
        $value = str_contains($source, 'constructor') ? '$entry->extra->value' : '$entry->injected?->value';
        if (str_contains($source, 'constructor')) {
            $after = 'public function __construct(public Leaf $dependency, public ExtraLeaf $extra) {}';
        }
        if ($source === 'private trait property') {
            $value = '$entry->injected()->value';
        }
        if ($source === 'overridden parent marker') {
            $after = str_replace('#[Inject]', '', $before);
        }

        $origin = 'Origin';
        $type = 'class';
        if (str_contains($source, 'trait')) {
            $type = 'trait';
            $this->source('ParentLayer', 'class ParentLayer { use Origin; }');
            $this->source('Entry', 'final class Entry extends ParentLayer {}');
            if ($source === 'nested trait constructor') {
                $this->source('Origin', 'trait Origin { use NestedOrigin; }');
                $origin = 'NestedOrigin';
            }
        } else {
            $override = $source === 'overridden parent marker'
                ? 'public function configure(Leaf $dependency): void { $this->injected = $dependency; }'
                : '';
            $this->source('Entry', "final class Entry extends Origin { {$override} }");
        }

        $this->source($origin, "{$type} {$origin} { {$before} }");

        return [$origin, "{$type} {$origin} { {$after} }", $value];
    }

    private function source(string $name, string $body): void
    {
        file_put_contents($this->path($name . '.php'), $this->sourceCode($body));
        touch($this->path($name . '.php'), time() - 60);
    }

    private function sourceCode(string $body): string
    {
        return "<?php\ndeclare(strict_types=1);\nnamespace CacheSourceFixture;\nuse Gacela\\Container\\Attribute\\Inject;\n{$body}\n";
    }

    private function change(string $name, string $replacement, bool $deleted): void
    {
        if ($deleted) {
            unlink($this->path($name . '.php'));

            return;
        }

        $this->source($name, $replacement);
        touch($this->path($name . '.php'), time() + 60);
    }

    private function writeCache(string $kind, ?string $buildStamp = null): void
    {
        $buildStamp ??= $kind === 'shared plans' ? 'deployment-1' : null;
        $stamp = var_export($buildStamp, true);
        $file = var_export($this->path('cache.php'), true);
        $body = match ($kind) {
            'shared plans' => "\$cache = new \\Gacela\\Container\\PlanCache(); \$container = new \\Gacela\\Container\\Container([], [], [], \$cache); \$container->compile(\$classes); \$cache->writeTo({$file}, {$stamp});",
            'factories' => "(new \\Gacela\\Container\\Container())->writeCompiledFactories(\$classes, {$file}, {$stamp});",
            default => "(new \\Gacela\\Container\\Container())->writeCompiledCache(\$classes, {$file}, {$stamp});",
        };
        $loader = $this->loader($kind);
        $result = $this->process("\$classes = [\\CacheSourceFixture\\Entry::class, \\CacheSourceFixture\\Unchanged::class, \\CacheSourceFixture\\Leaf::class];\n{$body}\n\$entries = {$loader}({$stamp}); \$result = ['entries' => array_keys(\$entries)];");

        self::assertContains('CacheSourceFixture\\Entry', $result['entries']);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function readCache(string $kind, string $value, bool $resolve): array
    {
        $loader = $this->loader($kind);
        $container = $kind === 'factories'
            ? '$container = new \\Gacela\\Container\\Container(); $container->useCompiledFactories($entries);'
            : '$container = new \\Gacela\\Container\\Container([], [], $entries);';
        $entry = $resolve ? '$entry = $container->get(\\CacheSourceFixture\\Entry::class);' : '';

        return $this->process(<<<PHP
\$entries = {$loader}(null);
\$autoloaded = class_exists(\\CacheSourceFixture\\Entry::class, false);
{$container}
{$entry}
\$result = ['entries' => array_keys(\$entries), 'autoloaded' => \$autoloaded, 'value' => {$value}, 'unchanged' => \$container->get(\\CacheSourceFixture\\Unchanged::class)->value];
PHP);
    }

    private function loader(string $kind): string
    {
        $file = var_export($this->path('cache.php'), true);
        if ($kind === 'shared plans') {
            return "(static fn (?string \$stamp): array => \\Gacela\\Container\\PlanCache::fromFile({$file}, \$stamp)->registry()->plans)";
        }

        $method = $kind === 'factories' ? 'loadCompiledFactories' : 'loadCompiledCache';

        return "(static fn (?string \$stamp): array => \\Gacela\\Container\\Container::{$method}({$file}, \$stamp))";
    }

    private function path(string $name): string
    {
        $file = $this->directory . '/' . $name;
        $this->files[$file] = true;

        return $file;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function process(string $body): array
    {
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $directory = var_export($this->directory, true);
        $script = $this->path('process-' . ++$this->processes . '.php');
        file_put_contents($script, <<<PHP
<?php
declare(strict_types=1);
require {$autoload};
spl_autoload_register(static function (string \$class): void {
    if (str_starts_with(\$class, 'CacheSourceFixture\\\\')) {
        \$file = {$directory} . '/' . substr(\$class, strlen('CacheSourceFixture\\\\')) . '.php';
        if (is_file(\$file)) { require \$file; }
    }
});
{$body}
echo json_encode(\$result, JSON_THROW_ON_ERROR);
PHP);
        $process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'max_execution_time=5', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr . $stdout);
        self::assertIsString($stdout);
        $result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);

        return $result;
    }
}
