<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

use DateTimeImmutable;

/**
 * Applies tenant module lifecycle rules before writing the tenant's enablement record.
 * The caller owns the transaction when a registered hook must be atomic with that write.
 */
final readonly class TenantModuleManager
{
    /** @param array<string, TenantModuleEnableHook> $hooks */
    public function __construct(
        private CompiledModuleRegistry $registry,
        private TenantModuleMutationRepository $repository,
        private TenantModuleConfigValidator $configValidator,
        private array $hooks = [],
    ) {
        foreach ($hooks as $moduleKey => $hook) {
            if (!is_string($moduleKey) || !$hook instanceof TenantModuleEnableHook) {
                throw new ModuleException('MODULE_HOOK_INVALID', 'Tenant module hooks must map module keys to TenantModuleEnableHook implementations.');
            }
            $this->assertTenantManageable($this->manifest($moduleKey));
        }
    }

    /**
     * Enables an installed module after checking tenant state, dependencies and configuration.
     * An unexpired enabled record is returned unchanged and does not run the hook again.
     *
     * @param array<string, mixed> $config Module-specific configuration to validate and persist.
     * @param string $source Audit origin for this lifecycle request.
     */
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
        $this->assertTenantManageable($manifest);
        $installation = $this->repository->installation($moduleKey);
        if ($installation === null) {
            throw new ModuleException('MODULE_NOT_INSTALLED', "Module {$moduleKey} is not installed.");
        }
        if ($installation->status !== 'active') {
            throw new ModuleException('MODULE_INSTALLATION_FAILED', "Module {$moduleKey} is not active.");
        }
        foreach ($this->requires($manifest) as $required) {
            if ($this->registry->isRequiredTenantFoundation($required)) {
                $requiredInstallation = $this->repository->installation($required);
                if ($requiredInstallation === null) {
                    throw new ModuleException('MODULE_DEPENDENCY_MISSING', "Tenant requires installed foundation {$required}.");
                }
                if ($requiredInstallation->status !== 'active') {
                    throw new ModuleException('MODULE_INSTALLATION_FAILED', "Required foundation {$required} is not active.");
                }
                continue;
            }
            $record = $this->repository->tenantModule($tenantId, $required);
            if ($record === null || !$record->isEffective($now)) {
                throw new ModuleException('MODULE_DEPENDENCY_MISSING', "Tenant requires enabled module {$required}.");
            }
        }
        $this->configValidator->assertValid($manifest, $config);
        $existing = $this->repository->tenantModule($tenantId, $moduleKey);
        if ($existing !== null && $existing->status === 'enabled'
            && ($existing->expiresAt === null || $now < $existing->expiresAt)) {
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

    /**
     * Disables a module only when no currently effective tenant module depends on it.
     * Repeating a disable for an already disabled record is idempotent.
     */
    public function disable(int $tenantId, string $moduleKey, DateTimeImmutable $now): TenantModuleRecord
    {
        $this->assertTenantManageable($this->manifest($moduleKey));
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
        $existing = $this->repository->tenantModule($tenantId, $moduleKey);
        if ($existing !== null && $existing->status === 'disabled') {
            return $existing;
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

    private function assertTenantManageable(ManifestDocument $manifest): void
    {
        if (($manifest->data['tenant']['enableable'] ?? false) !== true) {
            throw new ModuleException('MODULE_LIFECYCLE_PROTECTED', 'This required module cannot be changed through tenant enable/disable.');
        }
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
