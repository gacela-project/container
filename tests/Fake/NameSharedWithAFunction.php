<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

use function function_exists;

/**
 * A class whose fully-qualified name is also a defined function's.
 *
 * PHP keeps classes and functions in separate tables, so this is legal and the
 * two never collide — except through is_callable(), which is asked about a
 * string and can only answer from the function table. A binding testing
 * is_callable() before is_string() therefore *invokes* this name instead of
 * instantiating it, silently returning the wrong thing.
 *
 * Autoloading this file defines both, so the collision exists as soon as the
 * class is loaded.
 */
final class NameSharedWithAFunction implements RepositoryInterface
{
}

if (!function_exists(__NAMESPACE__ . '\NameSharedWithAFunction')) {
    /**
     * Deliberately returns something that is not a NameSharedWithAFunction, so
     * a container that invokes the name rather than instantiating it fails the
     * assertion instead of coincidentally passing it.
     */
    function NameSharedWithAFunction(): InMemoryRepository
    {
        return new InMemoryRepository();
    }
}
