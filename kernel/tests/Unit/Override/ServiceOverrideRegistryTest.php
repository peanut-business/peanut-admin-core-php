<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Override;

use PeanutAdmin\Kernel\Override\OverrideException;
use PeanutAdmin\Kernel\Override\ServiceOverride;
use PeanutAdmin\Kernel\Override\ServiceOverrideRegistry;
use PeanutAdmin\Kernel\Override\ServiceOverrideSlot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

interface FixtureService {}
interface OtherFixtureService {}
final class DefaultFixtureService implements FixtureService {}
final class ApplicationFixtureService implements FixtureService {}
final class InvalidFixtureService {}

final class ServiceOverrideRegistryTest extends TestCase
{
    private const KEY = 'peanut.fixture.service.example';

    public function testItResolvesDefaultsAndApplicationOverrides(): void
    {
        $slot = $this->slot();
        $defaults = new ServiceOverrideRegistry([$slot]);

        self::assertSame(DefaultFixtureService::class, $defaults->implementation(self::KEY));
        self::assertSame('default', $defaults->resolve(self::KEY)->source);
        self::assertSame([FixtureService::class => DefaultFixtureService::class], $defaults->bindings());

        $application = new ServiceOverrideRegistry([$slot], [new ServiceOverride(
            self::KEY,
            FixtureService::class,
            '1.0.0',
            ApplicationFixtureService::class,
        )]);

        self::assertSame(ApplicationFixtureService::class, $application->implementation(self::KEY));
        self::assertSame([FixtureService::class => ApplicationFixtureService::class], $application->bindings());
        self::assertSame([[
            'key' => self::KEY,
            'contract' => FixtureService::class,
            'contract_version' => '1.0.0',
            'source' => 'application',
        ]], $application->diagnostics());
        self::assertArrayNotHasKey('implementation', $application->diagnostics()[0]);
    }

    /** @param array<array-key, mixed> $slots */
    #[DataProvider('invalidSlotProvider')]
    public function testInvalidSlotsFailClosed(array $slots, string $errorCode): void
    {
        $this->expectOverrideError($errorCode, static fn() => new ServiceOverrideRegistry($slots));
    }

    /** @return iterable<string, array{array<array-key, mixed>, string}> */
    public static function invalidSlotProvider(): iterable
    {
        yield 'invalid key' => [[new ServiceOverrideSlot(
            'Peanut.fixture.service.example',
            FixtureService::class,
            '1.0.0',
            DefaultFixtureService::class,
        )], 'PHP_OVERRIDE_SLOT_KEY_INVALID'];
        yield 'key without service segment' => [[new ServiceOverrideSlot(
            'peanut.fixture.provider.example',
            FixtureService::class,
            '1.0.0',
            DefaultFixtureService::class,
        )], 'PHP_OVERRIDE_SLOT_KEY_INVALID'];
        yield 'duplicate key' => [[self::fixtureSlot(), self::fixtureSlot()], 'PHP_OVERRIDE_SLOT_KEY_DUPLICATE'];
        yield 'duplicate contract' => [[
            self::fixtureSlot(),
            new ServiceOverrideSlot('peanut.other.service.example', FixtureService::class, '1.0.0', DefaultFixtureService::class),
        ], 'PHP_OVERRIDE_CONTRACT_DUPLICATE'];
        yield 'contract is not interface' => [[new ServiceOverrideSlot(
            self::KEY,
            DefaultFixtureService::class,
            '1.0.0',
            DefaultFixtureService::class,
        )], 'PHP_OVERRIDE_CONTRACT_INVALID'];
        yield 'missing contract' => [[self::untrustedSlot(
            'PeanutAdmin\\MissingFixtureService',
            DefaultFixtureService::class,
        )], 'PHP_OVERRIDE_CONTRACT_INVALID'];
        yield 'invalid version' => [[new ServiceOverrideSlot(
            self::KEY,
            FixtureService::class,
            '1.0',
            DefaultFixtureService::class,
        )], 'PHP_OVERRIDE_VERSION_INVALID'];
        yield 'invalid default' => [[new ServiceOverrideSlot(
            self::KEY,
            FixtureService::class,
            '1.0.0',
            InvalidFixtureService::class,
        )], 'PHP_OVERRIDE_DEFAULT_INVALID'];
        yield 'missing default' => [[self::untrustedSlot(
            FixtureService::class,
            'PeanutAdmin\\MissingDefaultFixtureService',
        )], 'PHP_OVERRIDE_DEFAULT_INVALID'];
    }

    /** @param array<array-key, mixed> $overrides */
    #[DataProvider('invalidOverrideProvider')]
    public function testInvalidApplicationOverridesFailClosed(array $overrides, string $errorCode): void
    {
        $this->expectOverrideError(
            $errorCode,
            fn() => new ServiceOverrideRegistry([$this->slot()], $overrides),
        );
    }

    /** @return iterable<string, array{array<array-key, mixed>, string}> */
    public static function invalidOverrideProvider(): iterable
    {
        $valid = new ServiceOverride(self::KEY, FixtureService::class, '1.0.0', ApplicationFixtureService::class);
        yield 'unknown key' => [[new ServiceOverride(
            'peanut.unknown.service.example',
            FixtureService::class,
            '1.0.0',
            ApplicationFixtureService::class,
        )], 'PHP_OVERRIDE_KEY_UNKNOWN'];
        yield 'duplicate key' => [[$valid, $valid], 'PHP_OVERRIDE_KEY_DUPLICATE'];
        yield 'contract mismatch' => [[new ServiceOverride(
            self::KEY,
            OtherFixtureService::class,
            '1.0.0',
            ApplicationFixtureService::class,
        )], 'PHP_OVERRIDE_CONTRACT_MISMATCH'];
        yield 'version mismatch' => [[new ServiceOverride(
            self::KEY,
            FixtureService::class,
            '1.0.1',
            ApplicationFixtureService::class,
        )], 'PHP_OVERRIDE_VERSION_MISMATCH'];
        yield 'invalid implementation' => [[new ServiceOverride(
            self::KEY,
            FixtureService::class,
            '1.0.0',
            InvalidFixtureService::class,
        )], 'PHP_OVERRIDE_IMPLEMENTATION_INVALID'];
        yield 'missing implementation' => [[self::untrustedOverride(
            'PeanutAdmin\\MissingApplicationFixtureService',
        )], 'PHP_OVERRIDE_IMPLEMENTATION_INVALID'];
    }

    public function testUnknownResolutionFailsClosed(): void
    {
        $registry = new ServiceOverrideRegistry([$this->slot()]);
        $this->expectOverrideError(
            'PHP_OVERRIDE_RESOLUTION_UNKNOWN',
            static fn() => $registry->resolve('peanut.missing.service.example'),
        );
    }

    private function slot(): ServiceOverrideSlot
    {
        return self::fixtureSlot();
    }

    private static function fixtureSlot(): ServiceOverrideSlot
    {
        return new ServiceOverrideSlot(
            self::KEY,
            FixtureService::class,
            '1.0.0',
            DefaultFixtureService::class,
        );
    }

    private static function untrustedSlot(string $contract, string $defaultImplementation): ServiceOverrideSlot
    {
        return (new ReflectionClass(ServiceOverrideSlot::class))->newInstanceArgs([
            self::KEY,
            $contract,
            '1.0.0',
            $defaultImplementation,
        ]);
    }

    private static function untrustedOverride(string $implementation): ServiceOverride
    {
        return (new ReflectionClass(ServiceOverride::class))->newInstanceArgs([
            self::KEY,
            FixtureService::class,
            '1.0.0',
            $implementation,
        ]);
    }

    private function expectOverrideError(string $errorCode, callable $operation): void
    {
        try {
            $operation();
        } catch (OverrideException $exception) {
            self::assertSame($errorCode, $exception->errorCode);
            return;
        }

        self::fail("Expected override error: {$errorCode}");
    }
}
