<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Module;

use Closure;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleProvider;
use PeanutAdmin\Kernel\Module\ModuleProviderBindings;
use PHPUnit\Framework\TestCase;

final class ModuleProviderBindingsTest extends TestCase
{
    public function testCollectsMultipleProvidersInDeterministicContractOrder(): void
    {
        $factory = static fn(): BindingFixtureB => new BindingFixtureBImplementation();
        $providers = [
            new BindingFixtureProvider('example.b', [BindingFixtureB::class => $factory]),
            new BindingFixtureProvider('example.a', [BindingFixtureA::class => BindingFixtureAImplementation::class]),
        ];

        $expected = [
            BindingFixtureA::class => BindingFixtureAImplementation::class,
            BindingFixtureB::class => $factory,
        ];

        self::assertSame($expected, ModuleProviderBindings::collect($providers));
        self::assertSame($expected, ModuleProviderBindings::collect(array_reverse($providers)));
    }

    public function testRejectsDuplicateContractsBeforeTheHostMutatesItsContainer(): void
    {
        try {
            ModuleProviderBindings::collect([
                new BindingFixtureProvider('example.a', [BindingFixtureA::class => BindingFixtureAImplementation::class]),
                new BindingFixtureProvider('example.b', [BindingFixtureA::class => BindingFixtureAImplementation::class]),
            ]);
        } catch (ModuleException $exception) {
            self::assertSame('MODULE_REGISTRY_CONFLICT', $exception->errorCode);

            return;
        }

        self::fail('Duplicate Module container bindings must fail closed.');
    }
}

interface BindingFixtureA {}

interface BindingFixtureB {}

final class BindingFixtureAImplementation implements BindingFixtureA {}

final class BindingFixtureBImplementation implements BindingFixtureB {}

final readonly class BindingFixtureProvider implements ModuleProvider
{
    /** @param array<class-string, class-string|Closure> $bindings */
    public function __construct(
        private string $key,
        private array $bindings,
    ) {}

    public function moduleKey(): string
    {
        return $this->key;
    }

    public function bindings(): array
    {
        return $this->bindings;
    }
}
