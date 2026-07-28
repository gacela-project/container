<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Closure;
use Gacela\Container\Container;
use Gacela\Container\DependencyCacheManager;
use Gacela\Container\DependencyResolver;
use GacelaTest\Fake\ClassWithoutDependencies;
use GacelaTest\Fake\InMemoryRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use WeakMap;

/**
 * resolve() re-reflected its callable on every call, which cost more than the
 * resolution it was feeding. The plans are memoized now — by signature where the
 * callable has a name, and by the object itself where it does not.
 */
final class CallablePlanCacheTest extends TestCase
{
    public function test_a_named_callable_is_described_once(): void
    {
        $container = new Container();
        $handler = new CallablePlanHandler();

        $container->resolve([$handler, 'handle']);
        $container->resolve([$handler, 'handle']);

        self::assertSame(
            [CallablePlanHandler::class . '::handle'],
            array_keys(self::namedPlans($container)),
        );
    }

    /**
     * Two instances reach the same method with the same parameters, so keying
     * the plan per instance would never hit.
     */
    public function test_two_instances_of_a_class_share_one_plan(): void
    {
        $container = new Container();

        $container->resolve([new CallablePlanHandler(), 'handle']);
        $container->resolve([new CallablePlanHandler(), 'handle']);

        self::assertCount(1, self::namedPlans($container));
    }

    /**
     * The reason a closure's plan is keyed on the object rather than on
     * spl_object_id(): PHP reuses an id once an object is collected, so these
     * two closures — created and dropped in sequence — can share one. Keyed on
     * the id, the second would be called with the first's argument list.
     */
    public function test_closures_created_in_sequence_do_not_share_a_plan(): void
    {
        $container = new Container();

        $first = $container->resolve(static fn (ClassWithoutDependencies $a): string => $a::class);
        $second = $container->resolve(static fn (InMemoryRepository $b): string => $b::class);

        self::assertSame(ClassWithoutDependencies::class, $first);
        self::assertSame(InMemoryRepository::class, $second);
    }

    public function test_a_closure_plan_is_released_with_the_closure(): void
    {
        $container = new Container();

        $container->resolve(static fn (ClassWithoutDependencies $a): string => $a::class);

        // Weakly held, or a long-lived container would pin every closure ever
        // passed to resolve().
        self::assertCount(0, self::closurePlans($container));
    }

    public function test_a_retained_closure_keeps_its_plan(): void
    {
        $container = new Container();
        $callable = static fn (ClassWithoutDependencies $a): string => $a::class;

        $container->resolve($callable);

        self::assertCount(1, self::closurePlans($container));
    }

    public function test_every_callable_form_still_resolves(): void
    {
        $container = new Container();

        self::assertSame(
            ClassWithoutDependencies::class,
            $container->resolve([new CallablePlanHandler(), 'name']),
        );
        self::assertSame(
            ClassWithoutDependencies::class,
            $container->resolve([CallablePlanHandler::class, 'staticName']),
        );
        self::assertSame(
            ClassWithoutDependencies::class,
            $container->resolve(CallablePlanHandler::class . '::staticName'),
        );
        self::assertSame(
            ClassWithoutDependencies::class,
            $container->resolve(new CallablePlanInvokable()),
        );
    }

    public function test_runtime_parameters_still_override_a_cached_plan(): void
    {
        $container = new Container();
        $override = new ClassWithoutDependencies();

        $container->resolve([new CallablePlanHandler(), 'subject']);
        $subject = $container->resolve([new CallablePlanHandler(), 'subject'], ['subject' => $override]);

        self::assertSame($override, $subject);
    }

    /**
     * @return array<string, mixed>
     */
    private static function namedPlans(Container $container): array
    {
        /** @var array<string, mixed> */
        return self::resolverProperty($container, 'callablePlans') ?? [];
    }

    /**
     * @return WeakMap<Closure, mixed>|array{}
     */
    private static function closurePlans(Container $container): WeakMap|array
    {
        /** @var WeakMap<Closure, mixed>|null $plans */
        $plans = self::resolverProperty($container, 'closurePlans');

        return $plans ?? [];
    }

    private static function resolverProperty(Container $container, string $name): mixed
    {
        $cacheManager = (new ReflectionClass($container))
            ->getProperty('cacheManager')
            ->getValue($container);

        $resolver = (new ReflectionClass(DependencyCacheManager::class))
            ->getProperty('dependencyResolver')
            ->getValue($cacheManager);

        if ($resolver === null) {
            return null;
        }

        return (new ReflectionClass(DependencyResolver::class))
            ->getProperty($name)
            ->getValue($resolver);
    }
}

final class CallablePlanHandler
{
    public function handle(ClassWithoutDependencies $dependency): string
    {
        return $dependency::class;
    }

    public function name(ClassWithoutDependencies $dependency): string
    {
        return $dependency::class;
    }

    public function subject(ClassWithoutDependencies $subject): ClassWithoutDependencies
    {
        return $subject;
    }

    public static function staticName(ClassWithoutDependencies $dependency): string
    {
        return $dependency::class;
    }
}

final class CallablePlanInvokable
{
    public function __invoke(ClassWithoutDependencies $dependency): string
    {
        return $dependency::class;
    }
}
