<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

/**
 * Both an invokable and a named-method callable, so one fixture covers every
 * shape CallableKey has to tell apart.
 */
final class InvokableHandler
{
    public function __invoke(): string
    {
        return 'invoked';
    }

    public function handle(): string
    {
        return 'handled';
    }

    public static function statically(): string
    {
        return 'static';
    }
}
