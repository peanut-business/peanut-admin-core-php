<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Override;

final readonly class ServiceOverrideRegistry
{
    private const KEY_PATTERN = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*){2,}$/D';
    private const VERSION_PATTERN = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D';

    /** @var array<string, ServiceOverrideResolution> */
    private array $resolutions;

    /** @var array<class-string, class-string> */
    private array $serviceBindings;

    /** @var list<array{key: string, contract: class-string, contract_version: string, source: 'default'|'application'}> */
    private array $resolutionDiagnostics;

    /**
     * @param array<array-key, mixed> $slots
     * @param array<array-key, mixed> $overrides
     */
    public function __construct(array $slots, array $overrides = [])
    {
        $slotMap = [];
        $contracts = [];
        foreach ($slots as $slot) {
            if (!$slot instanceof ServiceOverrideSlot) {
                throw self::failure('PHP_OVERRIDE_SLOT_INVALID', 'Every service override slot must use ServiceOverrideSlot.');
            }
            self::validateKey($slot->key);
            if (isset($slotMap[$slot->key])) {
                throw self::failure('PHP_OVERRIDE_SLOT_KEY_DUPLICATE', "Duplicate service override slot: {$slot->key}");
            }
            self::validateContract($slot->contract, $slot->key);
            if (isset($contracts[$slot->contract])) {
                throw self::failure('PHP_OVERRIDE_CONTRACT_DUPLICATE', "Duplicate service contract: {$slot->key}");
            }
            self::validateVersion($slot->contractVersion, $slot->key);
            self::validateImplementation($slot->defaultImplementation, $slot->contract, $slot->key, true);
            $slotMap[$slot->key] = $slot;
            $contracts[$slot->contract] = true;
        }

        $resolutions = [];
        foreach ($slotMap as $key => $slot) {
            $resolutions[$key] = new ServiceOverrideResolution(
                $key,
                $slot->contract,
                $slot->contractVersion,
                $slot->defaultImplementation,
                'default',
            );
        }

        $seenOverrides = [];
        foreach ($overrides as $override) {
            if (!$override instanceof ServiceOverride) {
                throw self::failure('PHP_OVERRIDE_OVERRIDE_INVALID', 'Every service override must use ServiceOverride.');
            }
            if (isset($seenOverrides[$override->key])) {
                throw self::failure('PHP_OVERRIDE_KEY_DUPLICATE', "Duplicate service override: {$override->key}");
            }
            $seenOverrides[$override->key] = true;
            $slot = $slotMap[$override->key] ?? null;
            if ($slot === null) {
                throw self::failure('PHP_OVERRIDE_KEY_UNKNOWN', "Unknown service override: {$override->key}");
            }
            if ($override->contract !== $slot->contract) {
                throw self::failure('PHP_OVERRIDE_CONTRACT_MISMATCH', "Service override contract mismatch: {$override->key}");
            }
            if ($override->contractVersion !== $slot->contractVersion) {
                throw self::failure('PHP_OVERRIDE_VERSION_MISMATCH', "Service override version mismatch: {$override->key}");
            }
            self::validateImplementation($override->implementation, $slot->contract, $override->key, false);
            $resolutions[$override->key] = new ServiceOverrideResolution(
                $override->key,
                $slot->contract,
                $slot->contractVersion,
                $override->implementation,
                'application',
            );
        }

        $bindings = [];
        $diagnostics = [];
        foreach ($resolutions as $resolution) {
            $bindings[$resolution->contract] = $resolution->implementation;
            $diagnostics[] = [
                'key' => $resolution->key,
                'contract' => $resolution->contract,
                'contract_version' => $resolution->contractVersion,
                'source' => $resolution->source,
            ];
        }

        $this->resolutions = $resolutions;
        $this->serviceBindings = $bindings;
        $this->resolutionDiagnostics = $diagnostics;
    }

    public function resolve(string $key): ServiceOverrideResolution
    {
        return $this->resolutions[$key]
            ?? throw self::failure('PHP_OVERRIDE_RESOLUTION_UNKNOWN', "Unknown service override slot: {$key}");
    }

    /** @return class-string */
    public function implementation(string $key): string
    {
        return $this->resolve($key)->implementation;
    }

    /** @return array<class-string, class-string> */
    public function bindings(): array
    {
        return $this->serviceBindings;
    }

    /** @return list<array{key: string, contract: class-string, contract_version: string, source: 'default'|'application'}> */
    public function diagnostics(): array
    {
        return $this->resolutionDiagnostics;
    }

    private static function validateKey(string $key): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1 || !in_array('service', explode('.', $key), true)) {
            throw self::failure('PHP_OVERRIDE_SLOT_KEY_INVALID', "Invalid service override key: {$key}");
        }
    }

    private static function validateContract(string $contract, string $key): void
    {
        if (!interface_exists($contract)) {
            throw self::failure('PHP_OVERRIDE_CONTRACT_INVALID', "Service override contract must be an interface: {$key}");
        }
    }

    private static function validateVersion(string $version, string $key): void
    {
        if (preg_match(self::VERSION_PATTERN, $version) !== 1) {
            throw self::failure('PHP_OVERRIDE_VERSION_INVALID', "Invalid service override contract version: {$key}");
        }
    }

    /** @param class-string $contract */
    private static function validateImplementation(
        string $implementation,
        string $contract,
        string $key,
        bool $default,
    ): void {
        if (!class_exists($implementation) || !is_a($implementation, $contract, true)) {
            $code = $default ? 'PHP_OVERRIDE_DEFAULT_INVALID' : 'PHP_OVERRIDE_IMPLEMENTATION_INVALID';
            throw self::failure($code, "Service override implementation does not satisfy its contract: {$key}");
        }
    }

    private static function failure(string $code, string $message): OverrideException
    {
        return new OverrideException($code, $message);
    }
}
