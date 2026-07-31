<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\Exception\ContainerException;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\ControllerUsingService;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\InMemoryRepository;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\ServiceWithRepository;
use PHPUnit\Framework\TestCase;

/**
 * Loading is not a second registration path: every entry must end up doing what
 * the imperative call it stands for does, so most of these assert equivalence
 * rather than new behaviour.
 */
final class DefinitionLoadingTest extends TestCase
{
    public function test_a_bare_class_string_binds_the_abstract(): void
    {
        $container = new Container();
        $container->load([RepositoryInterface::class => InMemoryRepository::class]);

        self::assertInstanceOf(InMemoryRepository::class, $container->get(RepositoryInterface::class));
        self::assertTrue($container->bound(RepositoryInterface::class));
    }

    public function test_a_singleton_definition_shares_one_instance(): void
    {
        $container = new Container();
        $container->load([RepositoryInterface::class => ['singleton' => DatabaseRepository::class]]);

        self::assertSame(
            $container->get(RepositoryInterface::class),
            $container->get(RepositoryInterface::class),
        );
    }

    public function test_a_singleton_closure_is_memoized(): void
    {
        $calls = 0;
        $container = new Container();
        $container->load([
            'repository' => [
                'singleton' => static function () use (&$calls): InMemoryRepository {
                    ++$calls;

                    return new InMemoryRepository();
                },
            ],
        ]);

        $container->get('repository');
        $container->get('repository');

        self::assertSame(1, $calls);
    }

    public function test_a_singleton_object_is_stored_as_is(): void
    {
        $repository = new InMemoryRepository();
        $container = new Container();
        $container->load([RepositoryInterface::class => ['singleton' => $repository]]);

        self::assertSame($repository, $container->get(RepositoryInterface::class));
    }

    public function test_a_value_definition_stores_a_scalar(): void
    {
        $container = new Container();
        $container->load(['db.dsn' => ['value' => 'pgsql://localhost/app']]);

        self::assertSame('pgsql://localhost/app', $container->get('db.dsn'));
        self::assertContains('db.dsn', $container->getRegisteredServices());
    }

    public function test_a_factory_definition_yields_a_fresh_instance_per_resolution(): void
    {
        $container = new Container();
        $container->load([
            'config' => ['factory' => static fn (): ClassWithoutDependencies => new ClassWithoutDependencies()],
        ]);

        self::assertNotSame($container->get('config'), $container->get('config'));
    }

    public function test_an_alias_definition_points_at_another_id(): void
    {
        $container = new Container();
        $container->load([
            RepositoryInterface::class => ['singleton' => InMemoryRepository::class],
            'repository' => ['alias' => RepositoryInterface::class],
        ]);

        self::assertSame($container->get(RepositoryInterface::class), $container->get('repository'));
    }

    public function test_tags_group_ids_without_binding_them(): void
    {
        $container = new Container();
        $container->load([InMemoryRepository::class => ['tags' => ['repositories']]]);

        $tagged = iterator_to_array($container->tagged('repositories'), false);

        self::assertCount(1, $tagged);
        self::assertInstanceOf(InMemoryRepository::class, $tagged[0]);
        self::assertFalse($container->bound(InMemoryRepository::class));
    }

    public function test_tags_combine_with_a_binding_key(): void
    {
        $container = new Container();
        $container->load([
            RepositoryInterface::class => ['singleton' => InMemoryRepository::class, 'tags' => ['repositories']],
        ]);

        $tagged = iterator_to_array($container->tagged('repositories'), false);

        self::assertSame([$container->get(RepositoryInterface::class)], $tagged);
    }

    public function test_a_later_load_overrides_an_earlier_key(): void
    {
        $container = new Container();
        $container->load([RepositoryInterface::class => DatabaseRepository::class]);
        $container->load([RepositoryInterface::class => InMemoryRepository::class]);

        self::assertInstanceOf(InMemoryRepository::class, $container->get(RepositoryInterface::class));
    }

    public function test_tags_accumulate_across_loads_rather_than_overriding(): void
    {
        $container = new Container();
        $container->load([InMemoryRepository::class => ['tags' => ['repositories']]]);
        $container->load([InMemoryRepository::class => ['tags' => ['reporters']]]);

        self::assertCount(1, iterator_to_array($container->tagged('repositories'), false));
        self::assertCount(1, iterator_to_array($container->tagged('reporters'), false));
    }

    public function test_loading_a_value_over_a_resolved_one_hits_the_frozen_rules(): void
    {
        $container = new Container();
        $container->load(['db.dsn' => ['value' => 'pgsql://localhost/app']]);
        $container->get('db.dsn');

        self::assertTrue($container->isFrozen('db.dsn'));

        // Silently swapping an already-handed-out instance is exactly what
        // set() refuses to do; loading must not open a hole in that.
        $this->expectException(ContainerException::class);

        $container->load(['db.dsn' => ['value' => 'mysql://localhost/app']]);
    }

    public function test_definitions_interoperate_with_contextual_bindings(): void
    {
        $container = new Container();
        $container->load([RepositoryInterface::class => InMemoryRepository::class]);
        $container->when(ServiceWithRepository::class)
            ->needs(RepositoryInterface::class)
            ->give(DatabaseRepository::class);

        self::assertInstanceOf(
            DatabaseRepository::class,
            $container->get(ServiceWithRepository::class)->repository,
        );
        self::assertInstanceOf(InMemoryRepository::class, $container->get(RepositoryInterface::class));
    }

    public function test_a_scope_resolves_what_its_parent_loaded(): void
    {
        $container = new Container();
        $container->load([RepositoryInterface::class => ['singleton' => InMemoryRepository::class]]);

        $scope = $container->createScope();

        self::assertSame($container->get(RepositoryInterface::class), $scope->get(RepositoryInterface::class));
    }

    public function test_a_scope_can_shadow_a_loaded_definition_without_touching_its_parent(): void
    {
        $container = new Container();
        $container->load([RepositoryInterface::class => InMemoryRepository::class]);

        $scope = $container->createScope();
        $scope->load([RepositoryInterface::class => DatabaseRepository::class]);

        self::assertInstanceOf(DatabaseRepository::class, $scope->get(RepositoryInterface::class));
        self::assertInstanceOf(InMemoryRepository::class, $container->get(RepositoryInterface::class));
    }

    public function test_it_loads_a_php_file(): void
    {
        $container = new Container();
        $container->loadFile(self::fixture('services.php'));

        self::assertInstanceOf(InMemoryRepository::class, $container->get(RepositoryInterface::class));
        self::assertInstanceOf(DatabaseRepository::class, $container->get('repository.database'));
        self::assertSame('pgsql://localhost/app', $container->get('db.dsn'));
        self::assertSame($container->get(RepositoryInterface::class), $container->get('repository'));
        self::assertNotSame($container->get('config.factory'), $container->get('config.factory'));
        self::assertCount(1, iterator_to_array($container->tagged('reporters'), false));
    }

    public function test_it_loads_a_json_file(): void
    {
        $container = new Container();
        $container->loadFile(self::fixture('services.json'));

        self::assertInstanceOf(InMemoryRepository::class, $container->get(RepositoryInterface::class));
        self::assertInstanceOf(DatabaseRepository::class, $container->get('repository.database'));
        self::assertSame('pgsql://localhost/app', $container->get('db.dsn'));
        self::assertSame($container->get(RepositoryInterface::class), $container->get('repository'));
        self::assertCount(1, iterator_to_array($container->tagged('reporters'), false));
    }

    public function test_a_file_loaded_after_an_array_overrides_it(): void
    {
        $container = new Container();
        $container->load([RepositoryInterface::class => DatabaseRepository::class]);
        $container->loadFile(self::fixture('services.json'));

        self::assertInstanceOf(InMemoryRepository::class, $container->get(RepositoryInterface::class));
    }

    public function test_it_rejects_a_missing_file(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('could not be read');

        (new Container())->loadFile(self::fixture('does-not-exist.php'));
    }

    public function test_it_rejects_an_unsupported_extension(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('no supported extension');

        (new Container())->loadFile(self::fixture('services.xml'));
    }

    public function test_it_reads_a_yaml_file(): void
    {
        $container = new Container();
        $container->loadFile(self::fixture('services.yaml'));

        self::assertInstanceOf(InMemoryRepository::class, $container->get(RepositoryInterface::class));
        self::assertSame('pgsql://localhost/app', $container->get('db.dsn'));
        self::assertInstanceOf(DatabaseRepository::class, $container->get('repository.database'));
    }

    public function test_a_yaml_singleton_is_shared_like_any_other(): void
    {
        $container = new Container();
        $container->loadFile(self::fixture('services.yaml'));

        self::assertSame($container->get('repository.database'), $container->get('repository.database'));
    }

    public function test_yaml_tags_behave_like_any_other(): void
    {
        $container = new Container();
        $container->loadFile(self::fixture('services.yaml'));

        $tagged = iterator_to_array($container->tagged('reporters'));

        self::assertCount(1, $tagged);
        self::assertInstanceOf(InMemoryRepository::class, $tagged[0]);
    }

    public function test_it_reads_a_yml_file_too(): void
    {
        $container = new Container();
        $container->loadFile(self::fixture('services.yml'));

        self::assertInstanceOf(InMemoryRepository::class, $container->get(RepositoryInterface::class));
    }

    public function test_it_rejects_invalid_yaml_naming_the_file(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/invalid\.yaml/');

        (new Container())->loadFile(self::fixture('invalid.yaml'));
    }

    public function test_it_rejects_yaml_that_is_not_a_mapping(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('YAML mapping');

        (new Container())->loadFile(self::fixture('scalar.yaml'));
    }

    /**
     * A YAML sequence parses to a PHP list, which is an array — so it reaches
     * load() and is refused there, with the same message a JSON list gets.
     * Same input shape, same error, whichever format it arrived in.
     */
    public function test_a_yaml_sequence_is_refused_the_way_a_json_one_is(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('list rather than a map');

        (new Container())->loadFile(self::fixture('list-not-mapping.yaml'));
    }

    /**
     * The message is the whole feature when the parser is absent: without it a
     * .yaml file fails on an undefined class instead of saying what to install.
     */
    public function test_the_missing_parser_error_says_what_to_install(): void
    {
        $exception = ContainerException::yamlParserMissing('services.yaml');

        self::assertStringContainsString('composer require symfony/yaml', $exception->getMessage());
        self::assertStringContainsString('load()', $exception->getMessage());
    }

    public function test_it_rejects_a_php_file_that_returns_no_array(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('it did not return an array');

        (new Container())->loadFile(self::fixture('not-an-array.php'));
    }

    public function test_it_rejects_invalid_json(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('invalid.json');

        (new Container())->loadFile(self::fixture('invalid.json'));
    }

    public function test_it_rejects_a_json_list(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('definition ids must be strings');

        (new Container())->loadFile(self::fixture('list.json'));
    }

    public function test_an_error_from_a_file_names_the_file(): void
    {
        $file = self::fixture('unknown-key.json');

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("in '{$file}' is invalid");

        (new Container())->loadFile($file);
    }

    public function test_it_rejects_an_unknown_key(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("unknown key(s) 'lazy'");

        (new Container())->load([RepositoryInterface::class => ['lazy' => InMemoryRepository::class]]);
    }

    public function test_it_rejects_two_binding_keys_in_one_entry(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('singleton and value are mutually exclusive');

        (new Container())->load([
            RepositoryInterface::class => ['singleton' => InMemoryRepository::class, 'value' => 1],
        ]);
    }

    public function test_it_rejects_an_empty_definition(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('the definition is empty');

        (new Container())->load([RepositoryInterface::class => []]);
    }

    public function test_it_rejects_a_definition_that_is_neither_a_string_nor_an_array(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('expected a class-string or an array of definition keys');

        (new Container())->load([RepositoryInterface::class => 42]);
    }

    public function test_it_rejects_a_bare_string_that_is_not_a_class(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("use ['value' => …] for a scalar");

        (new Container())->load(['db.dsn' => 'pgsql://localhost/app']);
    }

    public function test_it_rejects_a_singleton_naming_no_class(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("'singleton' names 'Nope', which is not a class");

        (new Container())->load([RepositoryInterface::class => ['singleton' => 'Nope']]);
    }

    public function test_it_rejects_a_singleton_that_is_neither_class_callable_nor_object(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("'singleton' expects a class-string, a callable, or an object");

        (new Container())->load([RepositoryInterface::class => ['singleton' => 42]]);
    }

    public function test_it_rejects_a_factory_that_is_not_callable(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('it cannot come from a JSON file');

        (new Container())->load(['config' => ['factory' => InMemoryRepository::class]]);
    }

    public function test_it_rejects_an_alias_that_is_not_a_string(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("'alias' expects the id it points at");

        (new Container())->load(['repository' => ['alias' => 42]]);
    }

    public function test_it_rejects_tags_that_are_not_a_list_of_names(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("'tags' expects a non-empty list of tag names");

        (new Container())->load([InMemoryRepository::class => ['tags' => [42]]]);
    }

    public function test_it_rejects_an_empty_tag_list(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("'tags' expects a non-empty list of tag names");

        (new Container())->load([InMemoryRepository::class => ['tags' => []]]);
    }

    public function test_a_typo_is_reported_before_the_valid_keys_are_applied(): void
    {
        $container = new Container();

        try {
            $container->load([
                ControllerUsingService::class => ['singleton' => ControllerUsingService::class, 'nope' => true],
            ]);
        } catch (ContainerException) {
            self::assertFalse($container->bound(ControllerUsingService::class));
            return;
        }

        self::fail('Expected a ContainerException naming the unknown key');
    }

    private static function fixture(string $name): string
    {
        return __DIR__ . '/../Fake/definitions/' . $name;
    }
}
