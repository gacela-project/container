<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Closure;
use Gacela\Container\Container;
use Gacela\Container\Exception\CircularDependencyException;
use GacelaTest\Fake\ClassWithDependencyWithoutDependencies;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\ConsumerOfRegisteredOnlyService;
use GacelaTest\Fake\ForwardingContainer;
use GacelaTest\Fake\InMemoryRepository;
use GacelaTest\Fake\RegisteredOnlyService;
use GacelaTest\Fake\RepositoryInterface;
use GacelaTest\Fake\SelfReferential;
use GacelaTest\Fake\ServiceWithRepository;
use GacelaTest\Fake\SingletonAttributeService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TypeError;

use function iterator_to_array;

/**
 * A service registered under an id is what the container hands out for that id,
 * wherever the id is asked for — including as another class's constructor
 * parameter.
 *
 * Only bindings used to satisfy that. Everything the container answers from its
 * *instance* registry — set(), factory(), protect(), extend(), a 'value'
 * definition — and everything reached through an alias was invisible to nested
 * resolution, which autowired the class instead. When the class could not be
 * autowired, the first resolution of some unrelated *consumer* died naming a
 * scalar parameter of a class the author never asked the container to build.
 *
 * A scope never had the bug: it delegates an id it does not own to its parent,
 * which resolves it with get(). A root container had nobody to ask, so it built
 * its own copy — even of the ids it owned itself. That is why the fix reads as
 * making a root container do what a scope always did, and why every verb below
 * is also asserted to answer the same in both.
 *
 * @see https://github.com/gacela-project/gacela/issues/885
 */
final class NestedResolutionHonoursRegistrationsTest extends TestCase
{
    protected function setUp(): void
    {
        Container::resetStaticCaches();
    }

    protected function tearDown(): void
    {
        Container::resetStaticCaches();
    }

    public function test_a_stored_instance_is_injected_into_a_constructor_parameter(): void
    {
        $registered = new RegisteredOnlyService(42);

        $container = new Container();
        $container->set(RegisteredOnlyService::class, $registered);

        self::assertSame($registered, $container->get(RegisteredOnlyService::class));
        self::assertSame($registered, $container->get(ConsumerOfRegisteredOnlyService::class)->service);
    }

    public function test_a_stored_closure_service_is_injected_into_a_constructor_parameter(): void
    {
        $container = new Container();
        $container->set(
            RegisteredOnlyService::class,
            static fn (): RegisteredOnlyService => new RegisteredOnlyService(42),
        );

        self::assertSame(42, $container->get(RegisteredOnlyService::class)->number);
        self::assertSame(42, $container->get(ConsumerOfRegisteredOnlyService::class)->service->number);
    }

    /**
     * A stored closure is memoized on first read, so the same instance comes
     * back however it is asked for. This is the verb Gacela's `addLazy()` ends
     * up calling, and the shape reported in gacela#885.
     */
    public function test_a_stored_closure_service_is_shared_between_direct_and_nested_resolution(): void
    {
        $container = new Container();
        $container->set(
            RegisteredOnlyService::class,
            static fn (): RegisteredOnlyService => new RegisteredOnlyService(42),
        );

        self::assertSame(
            $container->get(RegisteredOnlyService::class),
            $container->get(ConsumerOfRegisteredOnlyService::class)->service,
        );
    }

    /**
     * factory() re-invokes the closure per read rather than memoizing it, and
     * that has to keep being true when the read is a constructor parameter.
     * This is what Gacela's `addFactory()` calls.
     */
    public function test_a_factory_service_is_invoked_for_each_injection(): void
    {
        $calls = 0;

        $container = new Container();
        $container->set(RegisteredOnlyService::class, $container->factory(
            static function () use (&$calls): RegisteredOnlyService {
                ++$calls;

                return new RegisteredOnlyService(42);
            },
        ));

        $first = $container->get(ConsumerOfRegisteredOnlyService::class)->service;
        $second = $container->get(ConsumerOfRegisteredOnlyService::class)->service;

        self::assertSame(42, $first->number);
        self::assertNotSame($first, $second, 'a factory service must not be shared between injections');
        self::assertSame(2, $calls);
    }

    /**
     * protect() means "hand this closure back rather than calling it", so the
     * closure *is* the service and there is nothing else to inject. A parameter
     * typed as the class then rejects it, which is the intended difference: the
     * registration is honoured, and PHP says the id does not hold what the
     * constructor asked for.
     *
     * The alternative — skipping protected entries and autowiring a second
     * object — is the silent failure this whole class exists to remove. A scope
     * has answered with this TypeError all along; a root container now agrees.
     */
    public function test_a_protected_closure_is_handed_over_rather_than_called(): void
    {
        $closure = static fn (): RegisteredOnlyService => new RegisteredOnlyService(42);

        $container = new Container();
        $container->set(RegisteredOnlyService::class, $container->protect($closure));

        self::assertSame($closure, $container->get(RegisteredOnlyService::class));

        $this->expectException(TypeError::class);
        $container->get(ConsumerOfRegisteredOnlyService::class);
    }

    public function test_an_alias_is_followed_for_a_constructor_parameter(): void
    {
        $registered = new RegisteredOnlyService(42);

        $container = new Container();
        $container->set('registered.service', $registered);
        $container->alias(RegisteredOnlyService::class, 'registered.service');

        self::assertSame($registered, $container->get(RegisteredOnlyService::class));
        self::assertSame($registered, $container->get(ConsumerOfRegisteredOnlyService::class)->service);
    }

    /**
     * An alias settles the question by being one. Its target here is nothing but
     * an autowirable class, so the container reports owning nothing for the id —
     * and building the name the alias is spelled as is still the wrong answer,
     * which for an interface is not an answer at all.
     */
    public function test_an_alias_to_a_plain_class_is_followed_for_a_constructor_parameter(): void
    {
        $container = new Container();
        $container->alias(RepositoryInterface::class, InMemoryRepository::class);

        self::assertInstanceOf(InMemoryRepository::class, $container->get(RepositoryInterface::class));
        self::assertInstanceOf(
            InMemoryRepository::class,
            $container->get(ServiceWithRepository::class)->repository,
        );
    }

    /**
     * Handing an id back to get() re-enters resolution for whatever it turns out
     * to name, so the id is bracketed by the resolving stack the way a
     * construction is. Without that a graph that comes back round to the id has
     * no floor and takes the process with it, instead of reporting the cycle
     * that every other path reports.
     */
    public function test_a_class_whose_stored_instance_was_removed_still_reports_its_cycle(): void
    {
        // Its constructor needs one of itself, so the only way to have one to
        // store is to skip the constructor.
        $stored = (new ReflectionClass(SelfReferential::class))->newInstanceWithoutConstructor();

        $container = new Container();
        $container->set(SelfReferential::class, $stored);
        $container->remove(SelfReferential::class);

        $this->expectException(CircularDependencyException::class);
        $container->get(SelfReferential::class);
    }

    public function test_an_extended_service_is_injected_rather_than_the_one_it_replaced(): void
    {
        $container = new Container();
        $container->set(RegisteredOnlyService::class, new RegisteredOnlyService(1));
        $container->extend(
            RegisteredOnlyService::class,
            static fn (RegisteredOnlyService $s): RegisteredOnlyService => new RegisteredOnlyService($s->number + 41),
        );

        self::assertSame(42, $container->get(RegisteredOnlyService::class)->number);
        self::assertSame(42, $container->get(ConsumerOfRegisteredOnlyService::class)->service->number);
    }

    public function test_a_value_definition_is_injected_into_a_constructor_parameter(): void
    {
        $registered = new RegisteredOnlyService(42);

        $container = new Container();
        $container->load([RegisteredOnlyService::class => ['value' => $registered]]);

        self::assertSame($registered, $container->get(RegisteredOnlyService::class));
        self::assertSame($registered, $container->get(ConsumerOfRegisteredOnlyService::class)->service);
    }

    /**
     * A 'factory' definition registers through factory(), so it answers like
     * one: a fresh instance per read, injections included.
     */
    public function test_a_factory_definition_is_injected_into_a_constructor_parameter(): void
    {
        $container = new Container();
        $container->load([
            RegisteredOnlyService::class => [
                'factory' => static fn (): RegisteredOnlyService => new RegisteredOnlyService(42),
            ],
        ]);

        self::assertSame(42, $container->get(RegisteredOnlyService::class)->number);
        self::assertSame(42, $container->get(ConsumerOfRegisteredOnlyService::class)->service->number);
    }

    /**
     * A tag is a list of ids, never a registration for one: `tagged()` resolves
     * each id with get(). So a tagged id asked for as a constructor parameter
     * answers exactly as its own registration does, and tagging changes nothing
     * either way. Asserted so that stays true.
     */
    public function test_tagging_an_id_does_not_change_what_a_constructor_parameter_gets(): void
    {
        $registered = new RegisteredOnlyService(42);

        $container = new Container();
        $container->set(RegisteredOnlyService::class, $registered);
        $container->tag([RegisteredOnlyService::class], 'services');

        self::assertSame([$registered], iterator_to_array($container->tagged('services')));
        self::assertSame($registered, $container->get(ConsumerOfRegisteredOnlyService::class)->service);
    }

    /**
     * A binding already worked; it is the one path nested resolution consulted.
     * Kept as a regression guard, because the fix runs ahead of it.
     */
    public function test_a_binding_is_still_injected_into_a_constructor_parameter(): void
    {
        $container = new Container();
        $container->bind(
            RegisteredOnlyService::class,
            static fn (): RegisteredOnlyService => new RegisteredOnlyService(42),
        );

        self::assertSame(42, $container->get(RegisteredOnlyService::class)->number);
        self::assertSame(42, $container->get(ConsumerOfRegisteredOnlyService::class)->service->number);
    }

    /**
     * get() reads the instance registry before the bindings, so nested
     * resolution has to as well — otherwise an id with both answers one thing
     * directly and another as a parameter.
     */
    public function test_a_stored_instance_outranks_a_binding_for_the_same_id(): void
    {
        $stored = new RegisteredOnlyService(42);

        $container = new Container();
        $container->bind(
            RegisteredOnlyService::class,
            static fn (): RegisteredOnlyService => new RegisteredOnlyService(1),
        );
        $container->set(RegisteredOnlyService::class, $stored);

        self::assertSame($stored, $container->get(RegisteredOnlyService::class));
        self::assertSame($stored, $container->get(ConsumerOfRegisteredOnlyService::class)->service);
    }

    /**
     * A contextual binding is the one thing that outranks the registration: it
     * answers for one consumer, which is narrower than "what this id holds".
     */
    public function test_a_contextual_binding_outranks_a_stored_instance(): void
    {
        $stored = new ClassWithoutDependencies();
        $contextual = new ClassWithoutDependencies();

        $container = new Container();
        $container->set(ClassWithoutDependencies::class, $stored);
        $container->when(ClassWithDependencyWithoutDependencies::class)
            ->needs(ClassWithoutDependencies::class)
            ->give($contextual);

        self::assertSame($stored, $container->get(ClassWithoutDependencies::class));
        self::assertSame(
            $contextual,
            $container->get(ClassWithDependencyWithoutDependencies::class)->classWithoutDependencies,
        );
    }

    /**
     * remove() forgets the instance, so the id goes back to being whatever the
     * container can make of it — as a parameter too. Nothing had to be
     * un-recorded for that: the id is still handed to get(), which autowires it
     * like any other, so the parameter and a direct call cannot disagree even
     * about an id that was registered and then was not.
     */
    public function test_removing_a_stored_instance_returns_the_parameter_to_autowiring(): void
    {
        $container = new Container();
        $container->set(ClassWithoutDependencies::class, new ClassWithoutDependencies());
        $container->remove(ClassWithoutDependencies::class);

        $consumer = $container->get(ClassWithDependencyWithoutDependencies::class);

        self::assertNotSame(
            $container->get(ClassWithoutDependencies::class),
            $consumer->classWithoutDependencies,
        );
    }

    /**
     * The optimisation that flattens a graph into a plain `new` must refuse a
     * class the container owns an instance of, or the second construction of a
     * consumer would disagree with the first. Three resolutions, because the
     * flattening is composed on the second.
     */
    public function test_every_resolution_of_a_consumer_agrees_with_the_first(): void
    {
        $stored = new ClassWithoutDependencies();

        $container = new Container();
        $container->set(ClassWithoutDependencies::class, $stored);

        self::assertSame($stored, $container->get(ClassWithDependencyWithoutDependencies::class)->classWithoutDependencies);
        self::assertSame($stored, $container->get(ClassWithDependencyWithoutDependencies::class)->classWithoutDependencies);
        self::assertSame($stored, $container->get(ClassWithDependencyWithoutDependencies::class)->classWithoutDependencies);
    }

    /**
     * The decorator seam: a wrapper that registered itself is what closures are
     * handed, and it is what a nested lookup goes through too — so the
     * registrations the wrapper can see are the ones that apply.
     */
    public function test_a_nested_id_is_asked_of_the_registered_facade(): void
    {
        $registered = new RegisteredOnlyService(42);

        $container = new Container();
        // Held: withSelfReference() keeps only a weak reference, so a facade
        // nothing else points at is collected before the first resolution.
        $facade = new ForwardingContainer($container);
        $container->withSelfReference($facade);
        $container->set(RegisteredOnlyService::class, $registered);

        self::assertSame($registered, $container->get(ConsumerOfRegisteredOnlyService::class)->service);
    }

    /**
     * A facade is held weakly, so one its caller has let go of is gone while the
     * container it wrapped carries on resolving. The container answers for
     * itself then — the rule DecoratorSeamsTest states for closures — and a
     * nested lookup follows it, rather than losing the registration along with
     * the facade.
     */
    public function test_a_nested_id_survives_the_facade_being_dropped(): void
    {
        $registered = new RegisteredOnlyService(42);

        $container = new Container();
        $container->withSelfReference(new ForwardingContainer($container));
        $container->set(RegisteredOnlyService::class, $registered);

        self::assertSame($registered, $container->get(ConsumerOfRegisteredOnlyService::class)->service);
    }

    /**
     * @param Closure(Container): void $register
     */
    #[DataProvider('registrationVerbs')]
    public function test_a_scope_answers_a_parameter_the_way_a_root_container_does(Closure $register): void
    {
        $root = new Container();
        $register($root);

        $scoped = new Container();
        $register($scoped);
        $scope = $scoped->createScope();

        self::assertSame(
            $root->get(ConsumerOfRegisteredOnlyService::class)->service->number,
            $scope->get(ConsumerOfRegisteredOnlyService::class)->service->number,
        );
    }

    /**
     * @return iterable<string, array{Closure(Container): void}>
     */
    public static function registrationVerbs(): iterable
    {
        yield 'set(instance)' => [
            static fn (Container $c) => $c->set(RegisteredOnlyService::class, new RegisteredOnlyService(42)),
        ];

        yield 'set(closure)' => [
            static fn (Container $c) => $c->set(
                RegisteredOnlyService::class,
                static fn (): RegisteredOnlyService => new RegisteredOnlyService(42),
            ),
        ];

        yield 'set(factory)' => [
            static fn (Container $c) => $c->set(RegisteredOnlyService::class, $c->factory(
                static fn (): RegisteredOnlyService => new RegisteredOnlyService(42),
            )),
        ];

        yield 'bind(closure)' => [
            static fn (Container $c) => $c->bind(
                RegisteredOnlyService::class,
                static fn (): RegisteredOnlyService => new RegisteredOnlyService(42),
            ),
        ];

        yield 'singleton(closure)' => [
            static fn (Container $c) => $c->singleton(
                RegisteredOnlyService::class,
                static fn (): RegisteredOnlyService => new RegisteredOnlyService(42),
            ),
        ];

        yield 'lazy(closure)' => [
            static fn (Container $c) => $c->lazy(
                RegisteredOnlyService::class,
                static fn (): RegisteredOnlyService => new RegisteredOnlyService(42),
            ),
        ];

        yield 'alias' => [
            static function (Container $c): void {
                $c->set('registered.service', new RegisteredOnlyService(42));
                $c->alias(RegisteredOnlyService::class, 'registered.service');
            },
        ];

        yield 'load(value)' => [
            static fn (Container $c) => $c->load([
                RegisteredOnlyService::class => ['value' => new RegisteredOnlyService(42)],
            ]),
        ];

        yield 'extend(stored)' => [
            static function (Container $c): void {
                $c->set(RegisteredOnlyService::class, new RegisteredOnlyService(1));
                $c->extend(
                    RegisteredOnlyService::class,
                    static fn (RegisteredOnlyService $s): RegisteredOnlyService => new RegisteredOnlyService(42),
                );
            },
        ];
    }

    /**
     * Lifetime marks are a separate axis and are deliberately *not* changed
     * here: a nested node is built afresh whether or not the class carries
     * #[Singleton], and `singleton()` with no concrete behaves the same — so
     * the attribute and the method still agree with each other.
     *
     * Both differ from a direct get(), which does share. That is the transient
     * children rule (see TransientChildResolutionTest): it decides how long an
     * instance lives, not which registration answers the id, which is what this
     * class covers. Asserted so a change to it is deliberate.
     */
    public function test_lifetime_marks_are_still_not_applied_to_nested_nodes(): void
    {
        $byAttribute = new Container();
        self::assertNotSame(
            $byAttribute->get(SingletonAttributeService::class),
            $byAttribute->get(ConsumerOfSingletonAttributeService::class)->service,
        );

        $byMethod = new Container();
        $byMethod->singleton(ClassWithoutDependencies::class);
        self::assertNotSame(
            $byMethod->get(ClassWithoutDependencies::class),
            $byMethod->get(ClassWithDependencyWithoutDependencies::class)->classWithoutDependencies,
        );
    }

    /**
     * An id nothing was registered for still costs nothing but the autowiring
     * it always did — the gate that makes the lookup affordable is what this
     * asserts, by proving a container that stores nothing under a class name
     * still builds a fresh child per resolution.
     */
    public function test_an_unregistered_parameter_is_still_autowired_fresh(): void
    {
        $container = new Container();
        $container->set('not.a.class', new ClassWithoutDependencies());

        self::assertNotSame(
            $container->get(ClassWithDependencyWithoutDependencies::class)->classWithoutDependencies,
            $container->get(ClassWithDependencyWithoutDependencies::class)->classWithoutDependencies,
        );
    }
}

final class ConsumerOfSingletonAttributeService
{
    public function __construct(
        public SingletonAttributeService $service,
    ) {
    }
}
