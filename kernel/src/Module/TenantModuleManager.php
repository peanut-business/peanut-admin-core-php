<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

use DateTimeImmutable;

final readonly class TenantModuleManager
{
    /** @param array<string, TenantModuleEnableHook> $hooks */
    public function __construct(
        private CompiledModuleRegistry $registry,
        private TenantModuleMutationRepository $repository,
        private TenantModuleConfigValidator $configValidator,
        private array $hooks = [],
    ) {}

    /** @param array<string, mixed> $config */
    public function enable(
        int $tenantId,
        string $moduleKey,
        array $config,
        DateTimeImmutable $now,
        string $source = 'manual',
        ?DateTimeImmutable $effectiveAt = null,
        ?DateTimeImmutable $expiresAt = null,
    ): TenantModuleRecord {
        if (!$this->repository->tenantIsActive($tenantId)) {
            throw new ModuleException('MODULE_TENANT_DISABLED', 'Only an active tenant can enable a module.');
        }
        $manifest = $this->manifest($moduleKey);
        $installation = $this->repository->installation($moduleKey);
        if ($installation === null) {
            throw new ModuleException('MODULE_NOT_INSTALLED', "Module {$moduleKey} is not installed.");
        }
        if ($installation->status !== 'active') {
            throw new ModuleException('MODULE_INSTALLATION_FAILED', "Module {$moduleKey} is not active.");
        }
        foreach ($this->requires($manifest) as $required) {
            $record = $this->repository->tenantModule($tenantId, $required);
            if ($record === null || !$record->isEffective($now)) {
                throw new ModuleException('MODULE_DEPENDENCY_MISSING', "Tenant requires enabled module {$required}.");
            }
        }
        $this->configValidator->assertValid($manifest, $config);
        $existing = $this->repository->tenantModule($tenantId, $moduleKey);
        if ($existing !== null && $existing->isEffective($now)) {
            return $existing;
        }
        ($this->hooks[$moduleKey] ?? null)?->enable($tenantId, $config);

        return $this->repository->enable(
            $tenantId,
            $moduleKey,
            $config,
            $now,
            $source,
            $effectiveAt,
            $expiresAt,
        );
    }

    public function disable(int $tenantId, string $moduleKey, DateTimeImmutable $now): TenantModuleRecord
    {
        foreach ($this->registry->modules as $candidate) {
            if (!in_array($moduleKey, $this->requires($candidate), true)) {
                continue;
            }
            $dependentKey = $this->key($candidate);
            $record = $this->repository->tenantModule($tenantId, $dependentKey);
            if ($record !== null && $record->isEffective($now)) {
                throw new ModuleException('MODULE_DEPENDENT_ACTIVE', "Enabled dependent blocks disable: {$dependentKey}");
            }
        }
        ($this->hooks[$moduleKey] ?? null)?->disable($tenantId);

        return $this->repository->disable($tenantId, $moduleKey, $now);
    }

    private function manifest(string $moduleKey): ManifestDocument
    {
        foreach ($this->registry->modules as $manifest) {
            if ($this->key($manifest) === $moduleKey) {
                return $manifest;
            }
        }
        throw new ModuleException('MODULE_NOT_INSTALLED', "Unknown module: {$moduleKey}");
    }

    private function key(ManifestDocument $manifest): string
    {
        $key = $manifest->data['key'] ?? null;
        return is_string($key) ? $key : throw new ModuleException('MODULE_MANIFEST_INVALID', 'Module key is missing.');
    }

    /** @return list<string> */
    private function requires(ManifestDocument $manifest): array
    {
        $tenant = $manifest->data['tenant'] ?? [];
        $requires = is_array($tenant) ? ($tenant['requires'] ?? []) : [];

        return is_array($requires) && array_is_list($requires)
            ? array_values(array_filter($requires, 'is_string'))
            : [];
    }
}
