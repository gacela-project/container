<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\ClassSource;
use Gacela\Container\Container;
use PHPUnit\Framework\TestCase;

final class SharedGraphCompilationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/container-shared-graph-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o700);
    }

    protected function tearDown(): void
    {
        @unlink($this->directory . '/graph.php');
        @unlink($this->directory . '/leaf.php');
        @unlink($this->directory . '/factories.php');
        rmdir($this->directory);
    }

    public function test_shared_dependency_graphs_keep_generated_files_bounded(): void
    {
        $classes = $this->graph(18);
        $file = $this->directory . '/factories.php';
        $compiled = (new Container())->writeCompiledFactories(ClassSource::fromList([$classes[0]]), $file);

        self::assertContains($classes[0], $compiled);
        self::assertLessThan(131072, filesize($file));
        self::assertArrayHasKey($classes[0], Container::loadCompiledFactories($file));
    }

    public function test_shared_code_keeps_instances_transient_and_receiving_overrides_win(): void
    {
        $classes = $this->graph(7);
        $root = $classes[0];
        $leaf = $classes[7];
        $file = $this->directory . '/factories.php';
        (new Container())->writeCompiledFactories(ClassSource::fromList([$root]), $file);
        $container = new Container();
        $container->useCompiledFactories(Container::loadCompiledFactories($file));
        $first = $container->get($root);
        $second = $container->get($root);
        self::assertNotSame($first, $second);
        self::assertNotSame($first->left, $first->right);
        self::assertNotSame($this->leaf($first->left), $this->leaf($first->right));

        $shared = new $leaf();
        $container->set($leaf, $shared);
        $overridden = $container->get($root);
        self::assertSame($shared, $this->leaf($overridden->left));
        self::assertSame($shared, $this->leaf($overridden->right));
    }

    public function test_a_changed_shared_source_discards_factories_that_call_helpers(): void
    {
        $classes = $this->graph(7);
        $file = $this->directory . '/factories.php';
        (new Container())->writeCompiledFactories(ClassSource::fromList([$classes[0]]), $file);
        touch($this->directory . '/leaf.php', time() + 60);
        clearstatcache();

        self::assertSame([], Container::loadCompiledFactories($file));
    }

    private function leaf(object $node): object
    {
        while (property_exists($node, 'left')) {
            $node = $node->left;
        }

        return $node;
    }

    /**
     * @return list<class-string>
     */
    private function graph(int $layers): array
    {
        $namespace = 'GeneratedGraphFixture' . bin2hex(random_bytes(6));
        $code = '<?php namespace ' . $namespace . ';';
        $classes = [];
        for ($i = 0; $i <= $layers; ++$i) {
            $classes[] = $namespace . '\\Node' . $i;
            $next = $i + 1;
            $constructor = $i === $layers ? '' : 'public function __construct(public Node' . $next . ' $left, public Node' . $next . ' $right) {}';
            $definition = 'final class Node' . $i . ' {' . $constructor . '}';
            if ($i === $layers) {
                $leafFile = $this->directory . '/leaf.php';
                file_put_contents($leafFile, '<?php namespace ' . $namespace . ';' . $definition);
                require $leafFile;
            } else {
                $code .= $definition;
            }
        }
        $file = $this->directory . '/graph.php';
        file_put_contents($file, $code);
        require $file;

        return $classes;
    }
}
