<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Closure;
use Gacela\Container\CallableKey;
use GacelaTest\Fake\InvokableHandler;
use PHPUnit\Framework\TestCase;

use function spl_object_id;

/**
 * Two keys with deliberately different grain.
 *
 * `for()` identifies a *callable*, so two instances of the same class must not
 * collide. `signatureFor()` identifies a *signature*, so they must — a plan
 * describes parameters, and keying one per instance would never hit.
 */
final class CallableKeyTest extends TestCase
{
    public function test_a_method_array_is_keyed_by_class_instance_and_method(): void
    {
        $handler = new InvokableHandler();

        $key = CallableKey::for([$handler, 'handle']);

        self::assertStringContainsString(InvokableHandler::class, $key);
        self::assertStringContainsString('#' . spl_object_id($handler), $key);
        self::assertStringContainsString('::handle', $key);
    }

    /**
     * The instance is part of the key, so two of them do not share one — which
     * is the whole difference from signatureFor().
     */
    public function test_two_instances_get_different_keys(): void
    {
        // Both held: spl_object_id is reused once an object is collected, so
        // two temporaries would share one and the assertion would be vacuous.
        $a = new InvokableHandler();
        $b = new InvokableHandler();

        self::assertNotSame(
            CallableKey::for([$a, 'handle']),
            CallableKey::for([$b, 'handle']),
        );
    }

    public function test_a_static_method_array_is_keyed_by_class_name(): void
    {
        $key = CallableKey::for([InvokableHandler::class, 'statically']);

        self::assertSame(InvokableHandler::class . '::statically', $key);
    }

    public function test_a_function_name_is_its_own_key(): void
    {
        self::assertSame('strlen', CallableKey::for('strlen'));
    }

    public function test_an_invokable_object_is_keyed_by_class_and_instance(): void
    {
        $handler = new InvokableHandler();

        $key = CallableKey::for($handler);

        self::assertStringContainsString(InvokableHandler::class, $key);
        self::assertStringContainsString('#' . spl_object_id($handler), $key);
    }

    public function test_a_closure_is_keyed_by_instance(): void
    {
        $closure = static fn (): int => 1;

        self::assertStringContainsString('#' . spl_object_id($closure), CallableKey::for($closure));
    }

    // ------------------------------------------------------------------
    // signatureFor(): coarser on purpose.
    // ------------------------------------------------------------------

    public function test_a_signature_ignores_which_instance_it_came_from(): void
    {
        $first = CallableKey::signatureFor([new InvokableHandler(), 'handle']);
        $second = CallableKey::signatureFor([new InvokableHandler(), 'handle']);

        self::assertSame($first, $second);
        self::assertSame(InvokableHandler::class . '::handle', $first);
    }

    public function test_a_signature_of_a_static_array_names_the_class(): void
    {
        self::assertSame(
            InvokableHandler::class . '::statically',
            CallableKey::signatureFor([InvokableHandler::class, 'statically']),
        );
    }

    public function test_a_signature_of_a_function_is_its_name(): void
    {
        self::assertSame('strlen', CallableKey::signatureFor('strlen'));
    }

    public function test_an_invokable_signature_names_its_invoke_method(): void
    {
        self::assertSame(
            InvokableHandler::class . '::__invoke',
            CallableKey::signatureFor(new InvokableHandler()),
        );
    }

    /**
     * A closure has no stable name, and spl_object_id is deliberately not used:
     * PHP reuses an id once an object is collected, so a fresh closure could
     * inherit the parameter list of a dead one. Null means "key on the object
     * itself" instead.
     */
    public function test_a_closure_has_no_signature(): void
    {
        self::assertNull(CallableKey::signatureFor(static fn (): int => 1));
    }

    public function test_a_first_class_callable_of_a_method_keeps_the_closure_rule(): void
    {
        $handler = new InvokableHandler();
        $callable = $handler->handle(...);

        self::assertInstanceOf(Closure::class, $callable);
        self::assertNull(CallableKey::signatureFor($callable));
    }
}
