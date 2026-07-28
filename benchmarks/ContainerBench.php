<?php

declare(strict_types=1);

namespace GacelaBench;

use Gacela\Container\Container;
use Gacela\Container\PlanCache;
use GacelaBench\Fixture\CallableHandler;
use GacelaBench\Fixture\ConsoleLogger;
use GacelaBench\Fixture\EagerExpensive;
use GacelaBench\Fixture\LazyExpensive;
use GacelaBench\Fixture\Level1;
use GacelaBench\Fixture\Level3;
use GacelaBench\Fixture\Level4;
use GacelaBench\Fixture\LoggerInterface;
use GacelaBench\Fixture\NoDependencies;
use GacelaBench\Fixture\SingletonService;
use GacelaBench\Fixture\WithBinding;
use GacelaBench\Fixture\WithInject;
use GacelaBench\Fixture\WithInjectedProperty;
use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\RetryThreshold;
use PhpBench\Attributes\Warmup;

/**
 * Resolution hot paths.
 *
 * The first four cases mirror the scenarios reported in #45 so numbers stay
 * directly comparable across versions.
 *
 * Report mode-of-iterations rather than mean, and always record the PHP version
 * and whether opcache/JIT was enabled alongside any figure.
 *
 * The retry threshold re-runs any iteration deviating 5% or more from the mean,
 * and is what makes the assertions below able to fire on a shared runner. It
 * buys more than it looks: measured over consecutive runs, the first phpbench
 * invocation of a session reads an order of magnitude noisier than the second
 * (±40% against ±8% on the worst subject), and CI stores its baseline on
 * exactly that first invocation.
 */
#[Revs(1000)]
#[Iterations(5)]
#[Warmup(1)]
#[RetryThreshold(5)]
final class ContainerBench
{
    /** How many sibling containers a modular application ends up with. */
    private const SIBLINGS = 10;

    private Container $container;

    private Container $boundContainer;

    private Container $compiledContainer;

    /** @var array<class-string, mixed> */
    private array $plans = [];

    /** @var array<class-string, callable(): object> */
    private array $factories = [];

    /** @var callable */
    private $callable;

    public function setUpColdPlans(): void
    {
        $this->plans = (new Container())->compile([Level1::class]);
    }

    public function setUpPlain(): void
    {
        $this->container = new Container();
    }

    public function setUpStoredInstance(): void
    {
        $this->container = new Container();
        $this->container->set('stored', new NoDependencies());
    }

    public function setUpCallable(): void
    {
        $this->container = new Container();
        $this->callable = [new CallableHandler(), 'handle'];
    }

    public function setUpBound(): void
    {
        $this->boundContainer = new Container([
            LoggerInterface::class => ConsoleLogger::class,
        ]);
    }

    public function setUpWarm(): void
    {
        $this->container = new Container();
        $this->container->warmUp([Level1::class]);
    }

    public function setUpCompiled(): void
    {
        $source = new Container();
        $plans = $source->compile([Level1::class]);

        $this->compiledContainer = new Container([], [], $plans);
    }

    #[BeforeMethods('setUpPlain')]
    public function benchResolveNoDependencies(): void
    {
        $this->container->get(NoDependencies::class);
    }

    #[BeforeMethods('setUpPlain')]
    public function benchResolveWithInject(): void
    {
        $this->container->get(WithInject::class);
    }

    #[BeforeMethods('setUpPlain')]
    public function benchResolveWithInjectedProperty(): void
    {
        $this->container->get(WithInjectedProperty::class);
    }

    #[BeforeMethods('setUpBound')]
    public function benchResolveWithBinding(): void
    {
        $this->boundContainer->get(WithBinding::class);
    }

    // Gated: catches a cliff like the +17-41% regression in #45, not drift.
    #[Assert('mode(variant.time.avg) < mode(baseline.time.avg) +/- 20%')]
    #[BeforeMethods('setUpPlain')]
    public function benchResolveDeepChain(): void
    {
        $this->container->get(Level1::class);
    }

    #[BeforeMethods('setUpWarm')]
    public function benchResolveDeepChainWarmedUp(): void
    {
        $this->container->get(Level1::class);
    }

    #[BeforeMethods('setUpCompiled')]
    public function benchResolveDeepChainCompiled(): void
    {
        $this->compiledContainer->get(Level1::class);
    }

    /**
     * A cold container per revolution — the shape of a real PHP request.
     *
     * The warm benchmarks above cannot show what warmUp() or compiled plans buy
     * you: after the first resolve the in-memory caches absorb everything, so
     * the remaining revolutions measure cache hits either way. Compare the three
     * cold subjects against each other, not against the warm ones.
     */
    // Gated: catches a cliff like the +17-41% regression in #45, not drift.
    #[Assert('mode(variant.time.avg) < mode(baseline.time.avg) +/- 20%')]
    public function benchColdResolveDeepChain(): void
    {
        (new Container())->get(Level1::class);
    }

    public function setUpColdFactories(): void
    {
        $file = sys_get_temp_dir() . '/phpbench-compiled-factories.php';
        (new Container())->writeCompiledFactories([Level1::class], $file);
        $this->factories = Container::loadCompiledFactories($file);
    }

    /**
     * Generated `new` expressions: no resolver on the path at all.
     */
    // Gated: this is the fastest documented path, so a regression here means
    // the generated file stopped being used rather than that it got slower.
    #[Assert('mode(variant.time.avg) < mode(baseline.time.avg) +/- 20%')]
    #[BeforeMethods('setUpColdFactories')]
    public function benchColdResolveDeepChainGenerated(): void
    {
        $container = new Container();
        $container->useCompiledFactories($this->factories);
        $container->get(Level1::class);
    }

    // Gated: the compiled-plans figure documented in docs/performance.md.
    #[Assert('mode(variant.time.avg) < mode(baseline.time.avg) +/- 20%')]
    #[BeforeMethods('setUpColdPlans')]
    public function benchColdResolveDeepChainCompiled(): void
    {
        (new Container([], [], $this->plans))->get(Level1::class);
    }

    /**
     * The modular shape: one container per module, all resolving classes they
     * have in common. Every container plans the chain from scratch.
     */
    public function benchColdResolveAcrossSiblings(): void
    {
        for ($i = 0; $i < self::SIBLINGS; ++$i) {
            (new Container())->get(Level1::class);
        }
    }

    /**
     * The same, with one plan cache handed to all of them: the first container
     * reflects the chain and the rest read it.
     */
    // Gated: the PlanCache figure documented in docs/performance.md, and the
    // one most exposed to a change in what a plan holds.
    #[Assert('mode(variant.time.avg) < mode(baseline.time.avg) +/- 20%')]
    public function benchColdResolveAcrossSiblingsSharingPlans(): void
    {
        $plans = new PlanCache();

        for ($i = 0; $i < self::SIBLINGS; ++$i) {
            (new Container([], [], [], $plans))->get(Level1::class);
        }
    }

    /**
     * A graph whose expensive branch is never touched. The lazy variant should
     * not pay for constructing it.
     */
    public function benchColdResolveUntouchedEager(): void
    {
        (new Container())->get(EagerExpensive::class);
    }

    public function benchColdResolveUntouchedLazy(): void
    {
        (new Container())->get(LazyExpensive::class);
    }

    #[BeforeMethods('setUpPlain')]
    public function benchMakeTransient(): void
    {
        $this->container->make(Level1::class);
    }

    #[BeforeMethods('setUpPlain')]
    public function benchMakeWithRuntimeParameters(): void
    {
        $this->container->make(Level3::class, ['level4' => new Level4()]);
    }

    #[BeforeMethods('setUpPlain')]
    public function benchResolveSingleton(): void
    {
        $this->container->get(SingletonService::class);
    }

    /**
     * Invoking a callable through the container. Every other reflection result
     * is memoized, and this one was not, so the subject exists to keep it that
     * way.
     */
    #[BeforeMethods('setUpCallable')]
    public function benchResolveCallable(): void
    {
        $this->container->resolve($this->callable);
    }

    /**
     * Creating a scope must stay constant-time however much the parent holds,
     * and however many scopes it has already created.
     */
    #[BeforeMethods('setUpPlain')]
    public function benchCreateScope(): void
    {
        $this->container->createScope();
    }

    /**
     * Reading a set() instance, the only path InstanceRegistry::get() serves.
     */
    #[BeforeMethods('setUpStoredInstance')]
    public function benchGetStoredInstance(): void
    {
        $this->container->get('stored');
    }

    #[BeforeMethods('setUpPlain')]
    public function benchHasHit(): void
    {
        $this->container->has(NoDependencies::class);
    }

    #[BeforeMethods('setUpPlain')]
    public function benchHasMiss(): void
    {
        $this->container->has('not-a-registered-service');
    }
}
