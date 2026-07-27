<?php

declare(strict_types=1);

namespace Gacela\Container;

/**
 * Why the generator left a class out of the compiled factories.
 *
 * One case per branch that can refuse a class, so the set is the compiler's
 * conservatism made machine-readable rather than a parallel list to keep in
 * sync. Adding a check to ContainerCompiler means adding a case here.
 *
 * @api
 */
enum CompilationSkipReason: string
{
    /**
     * A binding could be changed after compilation, so baking it in would diverge.
     */
    case Bound = 'bound';

    /**
     * Registered through lazy(): construction is deferred, which a `new` expression is not.
     */
    case LazyRegistration = 'lazy-registration';

    /**
     * An interface, an abstract class, or a class the generator cannot build.
     */
    case NotInstantiable = 'not-instantiable';

    /**
     * #[Singleton], #[Factory] or #[Lazy] — lifetime belongs to the runtime.
     */
    case LifetimeAttribute = 'lifetime-attribute';

    /**
     * A `new` expression cannot assign #[Inject] properties.
     */
    case InjectedProperty = 'injected-property';

    /**
     * An #[Inject] parameter is resolved at runtime.
     */
    case InjectedParameter = 'injected-parameter';

    /**
     * A scalar or untyped parameter, whose value may come from a contextual binding.
     */
    case ScalarParameter = 'scalar-parameter';

    /**
     * The class takes part in a dependency cycle.
     */
    case DependencyCycle = 'dependency-cycle';

    /**
     * The planner never described the class, so there is nothing to generate from.
     */
    case NoPlan = 'no-plan';

    /**
     * One of its constructor dependencies could not be compiled.
     */
    case Dependency = 'dependency';
}
