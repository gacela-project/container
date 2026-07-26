<?php

declare(strict_types=1);

namespace GacelaBench;

use Gacela\Container\Container;
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
use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

/**
 * Resolution hot paths.
 *
 * The first four cases mirror the scenarios reported in #45 so numbers stay
 * directly comparable across versions.
 *
 * Report mode-of-iterations rather than mean, and always record the PHP version
 * and whether opcache/JIT was enabled alongside any figure.
 */
#[Revs(1000)]
#[Iterations(5)]
#[Warmup(1)]
final class ContainerBench
{
    private Container $container;

    private Container $boundContainer;

    private Container $compiledContainer;

    /** @var array<class-string, mixed> */
    private array $plans = [];

    public function setUpColdPlans(): void
    {
        $this->plans = (new Container())->compile([Level1::class]);
    }

    public function setUpPlain(): void
    {
        $this->container = new Container();
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

    #[BeforeMethods('setUpBound')]
    public function benchResolveWithBinding(): void
    {
        $this->boundContainer->get(WithBinding::class);
    }

    // Gated in CI: catches a cliff like the +17-41% regression in #45,
    // not 3% drift. Only subjects with rstdev under ~1.5% are gated.
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
    // Gated in CI: catches a cliff like the +17-41% regression in #45,
    // not 3% drift. Only subjects with rstdev under ~1.5% are gated.
    #[Assert('mode(variant.time.avg) < mode(baseline.time.avg) +/- 20%')]
    public function benchColdResolveDeepChain(): void
    {
        (new Container())->get(Level1::class);
    }

    // Gated in CI: catches a cliff like the +17-41% regression in #45,
    // not 3% drift. Only subjects with rstdev under ~1.5% are gated.
    #[Assert('mode(variant.time.avg) < mode(baseline.time.avg) +/- 20%')]
    #[BeforeMethods('setUpColdPlans')]
    public function benchColdResolveDeepChainCompiled(): void
    {
        (new Container([], [], $this->plans))->get(Level1::class);
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
