<?php

declare(strict_types=1);

namespace GacelaTest\Unit;

use Gacela\Container\Container;
use Gacela\Container\Exception\DependencyInvalidArgumentException;
use Gacela\Container\Exception\DependencyNotFoundException;
use GacelaTest\Fake\ClassWithInterfaceDependencies;
use GacelaTest\Fake\ClassWithIntersectionParameter;
use GacelaTest\Fake\ClassWithUnionParameter;
use GacelaTest\Fake\OuterOfWithoutDefaults;
use GacelaTest\Fake\Person;
use GacelaTest\Fake\PersonInterface;
use GacelaTest\Fake\PersonWithoutDefaultValues;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a failed resolution says: the parameter, what asked for it, and a fix
 * that compiles.
 */
final class ResolutionErrorMessagesTest extends TestCase
{
    /**
     * @param class-string $className
     */
    #[DataProvider('typesNamingNoSingleClass')]
    public function test_a_type_naming_no_single_class_is_a_container_error(string $className): void
    {
        try {
            (new Container())->get($className);
            self::fail('a union or intersection parameter was resolved');
        } catch (DependencyInvalidArgumentException $exception) {
            self::assertStringContainsString("parameter '\$value' in '{$className}'", $exception->getMessage());
            self::assertStringContainsString("needs('\$value')", $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function typesNamingNoSingleClass(): iterable
    {
        yield 'union' => [ClassWithUnionParameter::class];
        yield 'intersection' => [ClassWithIntersectionParameter::class];
    }

    public function test_a_scalar_error_starts_the_chain_at_the_class_asked_for(): void
    {
        $this->expectException(DependencyInvalidArgumentException::class);
        $this->expectExceptionMessage('Resolution chain: ' . OuterOfWithoutDefaults::class . ' -> ' . PersonWithoutDefaultValues::class);

        (new Container())->get(OuterOfWithoutDefaults::class);
    }

    public function test_a_scalar_error_suggests_code_that_compiles(): void
    {
        try {
            (new Container())->get(PersonWithoutDefaultValues::class);
            self::fail('a scalar parameter was resolved');
        } catch (DependencyInvalidArgumentException $exception) {
            self::assertStringContainsString("when(PersonWithoutDefaultValues::class)->needs('\$name')->give(<value>)", $exception->getMessage());
            self::assertStringNotContainsString("= 'default'", $exception->getMessage());
        }
    }

    public function test_a_missing_binding_names_what_needed_it(): void
    {
        $this->expectException(DependencyNotFoundException::class);
        $this->expectExceptionMessage('Needed by parameter $person of ' . ClassWithInterfaceDependencies::class . '::__construct().');

        (new Container())->get(ClassWithInterfaceDependencies::class);
    }

    public function test_a_scalar_parameter_of_a_callable_says_to_pass_it_in(): void
    {
        $this->expectException(DependencyInvalidArgumentException::class);
        $this->expectExceptionMessage("of the callable.\nScalar types (string, int, float, bool, array) cannot be auto-resolved.\n\nPass it in: \$container->resolve(\$callable, ['name' => <value>])");

        (new Container())->resolve(static fn (string $name): string => $name);
    }

    public function test_a_missing_binding_reads_as_one_message_with_its_fix_and_suggestions(): void
    {
        $container = new Container([PersonInterface::class . 's' => Person::class]);

        try {
            $container->get(ClassWithInterfaceDependencies::class);
            self::fail('an unbound interface was resolved');
        } catch (DependencyNotFoundException $exception) {
            $message = $exception->getMessage();
            self::assertStringStartsWith('Nothing is bound to "' . PersonInterface::class . '"', $message);
            self::assertStringContainsString("\nResolution chain: " . ClassWithInterfaceDependencies::class . "\n", $message);
            self::assertStringContainsString(
                "\$container->bind(PersonInterface::class, YourImplementation::class);\n\nDid you mean one of these?\n  - " . PersonInterface::class . "s\n\nSee ",
                $message,
            );
            self::assertStringEndsWith('docs/bindings.md', $message);
        }
    }

    public function test_get_or_fail_on_an_unknown_id_says_it_resolved_to_nothing(): void
    {
        $this->expectException(DependencyNotFoundException::class);
        $this->expectExceptionMessage('Could not resolve a non-null instance for "no-such-service".');

        (new Container())->getOrFail('no-such-service');
    }

    public function test_get_or_fail_on_an_unbound_interface_says_to_bind_it(): void
    {
        $this->expectException(DependencyNotFoundException::class);
        $this->expectExceptionMessage('Bind it to a concrete class');

        (new Container())->getOrFail(PersonInterface::class);
    }
}
