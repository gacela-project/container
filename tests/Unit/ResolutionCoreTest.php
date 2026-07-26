<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\Exception\DependencyNotFoundException;
use Gacela\Container\FuzzyMatcher;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\ClassWithRelationship;
use GacelaTest\Fake\DatabaseRepository;
use GacelaTest\Fake\FactoryAttributeService;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\ServiceWithMixedConstructor;
use GacelaTest\Fake\ServiceWithRepository;
use GacelaTest\Fake\ServiceWithUnionType;
use GacelaTest\Fake\SingletonAttributeService;
use PHPUnit\Framework\TestCase;

use function count;

/**
 * Behaviour of DependencyResolver and DependencyCacheManager that the rest of
 * the suite exercises only incidentally.
 *
 * Each test here corresponds to a mutant that survived before it existed, so
 * the assertions are deliberately specific about the observable difference
 * rather than just "it resolved".
 */
final class ResolutionCoreTest extends TestCase
{
    public function test_factory_attribute_does_not_cache_dependencies(): void
    {
        $container = new Container();
        $before = $container->getStats()['cached_dependencies'];

        $container->get(FactoryAttributeService::class);

        // The #[Factory] path deliberately skips the dependency cache so every
        // resolution is rebuilt. Caching it would defeat the attribute.
        self::assertSame($before, $container->getStats()['cached_dependencies']);
    }

    public function test_a_plain_class_does_cache_its_dependencies(): void
    {
        $container = new Container();
        $before = $container->getStats()['cached_dependencies'];

        $container->get(ClassWithoutDependencies::class);

        self::assertGreaterThan($before, $container->getStats()['cached_dependencies']);
    }

    public function test_factory_attribute_returns_a_fresh_instance_each_time(): void
    {
        $container = new Container();

        self::assertNotSame(
            $container->get(FactoryAttributeService::class),
            $container->get(FactoryAttributeService::class),
        );
    }

    public function test_singleton_and_factory_attributes_are_not_confused(): void
    {
        $container = new Container();

        // Both attributes are looked up through one cache. A key that ignored
        // the attribute type would make the second lookup answer the first.
        $singletonFirst = $container->get(SingletonAttributeService::class);
        $singletonSecond = $container->get(SingletonAttributeService::class);
        $factoryFirst = $container->get(FactoryAttributeService::class);
        $factorySecond = $container->get(FactoryAttributeService::class);

        self::assertSame($singletonFirst, $singletonSecond, '#[Singleton] must be shared');
        self::assertNotSame($factoryFirst, $factorySecond, '#[Factory] must be fresh');
    }

    public function test_the_attribute_lookup_order_does_not_change_the_outcome(): void
    {
        // Same as above with the resolution order reversed, so a shared cache
        // key cannot pass by accident of ordering.
        $container = new Container();

        $factoryFirst = $container->get(FactoryAttributeService::class);
        $factorySecond = $container->get(FactoryAttributeService::class);
        $singletonFirst = $container->get(SingletonAttributeService::class);
        $singletonSecond = $container->get(SingletonAttributeService::class);

        self::assertNotSame($factoryFirst, $factorySecond);
        self::assertSame($singletonFirst, $singletonSecond);
    }

    public function test_warm_up_continues_past_a_class_that_does_not_exist(): void
    {
        $container = new Container();
        $before = $container->getStats()['cached_dependencies'];

        /** @psalm-suppress ArgumentTypeCoercion */
        $container->warmUp(['GacelaTest\Fake\NoSuchClassAtAll', ClassWithRelationship::class]);

        // A bogus entry must be skipped, not abort the rest of the list.
        self::assertGreaterThan($before, $container->getStats()['cached_dependencies']);
    }

    public function test_runtime_parameters_do_not_stop_later_parameters_resolving(): void
    {
        $container = new Container();
        $person = new Person('overridden');

        // Overriding the FIRST parameter must not short-circuit resolution of
        // the second one.
        $result = $container->make(ClassWithRelationship::class, ['person1' => $person]);

        self::assertSame($person, $result->person1);
        self::assertInstanceOf(Person::class, $result->person2);
        self::assertNotSame($person, $result->person2);
    }

    public function test_a_callable_with_several_parameters_resolves_all_of_them(): void
    {
        $container = new Container();

        $result = $container->resolve(
            static fn (ClassWithoutDependencies $a, Person $b, ClassWithRelationship $c): array => [$a, $b, $c],
        );

        self::assertCount(3, $result);
        self::assertInstanceOf(ClassWithoutDependencies::class, $result[0]);
        self::assertInstanceOf(Person::class, $result[1]);
        self::assertInstanceOf(ClassWithRelationship::class, $result[2]);
    }

    public function test_a_union_typed_parameter_falls_back_to_its_default(): void
    {
        $container = new Container();

        // A union type is not a ReflectionNamedType, so it is neither a class
        // to resolve nor a detected scalar; the default has to carry it.
        self::assertSame(7, $container->get(ServiceWithUnionType::class)->value);
    }

    public function test_the_not_found_message_suggests_the_bound_abstract(): void
    {
        // Note the deliberate typo in the bound key. Resolving the real
        // interface as a *constructor dependency* is what reaches the
        // suggestion path; a top-level get() on an unbound interface just
        // returns null.
        $container = new Container(['GacelaTest\Fake\RepositoryInterfac' => DatabaseRepository::class]);

        $this->expectException(DependencyNotFoundException::class);
        // Suggestions come from the bound abstracts (the array keys), not from
        // the concretes they map to.
        $this->expectExceptionMessage('- GacelaTest\Fake\RepositoryInterfac');

        $container->get(ServiceWithRepository::class);
    }

    public function test_an_unbound_interface_resolved_directly_returns_null(): void
    {
        $container = new Container();

        // Documents the asymmetry relied on above: get() is lenient at the top
        // level, strict when the interface is someone's dependency.
        self::assertNull($container->get(RepositoryInterface::class));
    }

    public function test_the_dependency_tree_skips_scalars_but_keeps_walking(): void
    {
        $tree = (new Container())->getDependencyTree(ServiceWithMixedConstructor::class);

        // A builtin type is skipped, not treated as a class...
        self::assertNotContains('string', $tree);
        // ...and skipping it must not abandon the parameters that follow.
        self::assertContains(Person::class, $tree);
        self::assertContains(ClassWithoutDependencies::class, $tree);
    }

    public function test_the_dependency_tree_deduplicates_without_stopping(): void
    {
        $tree = (new Container())->getDependencyTree(ServiceWithMixedConstructor::class);

        // Person appears twice in the constructor. It must be recorded once,
        // and the duplicate must not end the walk before the last parameter.
        self::assertSame([Person::class], array_values(array_filter(
            $tree,
            static fn (string $t): bool => $t === Person::class,
        )));
        self::assertContains(ClassWithoutDependencies::class, $tree);
    }

    public function test_suggestions_are_ordered_with_the_closest_match_first(): void
    {
        $suggestions = FuzzyMatcher::findSimilar('UserService', [
            'UserServiceFactoryBuilder',
            'UserServic',
        ]);

        self::assertSame('UserServic', $suggestions[0]);
    }

    public function test_suggestions_are_capped(): void
    {
        $suggestions = FuzzyMatcher::findSimilar('UserService', [
            'UserServic',
            'UserServce',
            'UserSrvice',
            'UsurService',
            'UserServicz',
        ]);

        self::assertLessThanOrEqual(3, count($suggestions));
    }

    public function test_no_candidates_yields_no_suggestions(): void
    {
        self::assertSame([], FuzzyMatcher::findSimilar('UserService', []));
    }
}
