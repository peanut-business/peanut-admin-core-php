<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

use DateTimeImmutable;

final readonly class ModuleGuard
{
    public function __construct(private ModuleRuntimeRepository $repository) {}

    public function assertMemberAccess(
        int $tenantId,
        string $moduleKey,
        bool $permissionGranted,
        DateTimeImmutable $now,
    ): void {
        $this->assertDeployment($moduleKey);
        $this->assertTenant($tenantId, $moduleKey, $now);
        if (!$permissionGranted) {
            throw new ModuleException('AUTHORIZATION_PERMISSION_DENIED', 'Member permission is required.');
        }
    }

    public function assertDeployment(string $moduleKey): ModuleInstallationRecord
    {
        $installation = $this->repository->installation($moduleKey);
        if ($installation === null) {
            throw new ModuleException('MODULE_NOT_INSTALLED', "Module {$moduleKey} is not installed.");
        }
        if ($installation->status !== 'active') {
            throw new ModuleException('MODULE_INSTALLATION_FAILED', "Module {$moduleKey} is not active.");
        }

        return $installation;
    }

    public function assertTenant(int $tenantId, string $moduleKey, DateTimeImmutable $now): TenantModuleRecord
    {
        $record = $this->repository->tenantModule($tenantId, $moduleKey);
        if ($record === null || $record->status === 'disabled') {
            throw new ModuleException('MODULE_TENANT_DISABLED', "Module {$moduleKey} is disabled for tenant.");
        }
        if (!$record->isEffective($now)) {
            throw new ModuleException('MODULE_TENANT_NOT_EFFECTIVE', "Module {$moduleKey} is outside its effective window.");
        }

        return $record;
    }

    public function cacheTtl(
        int $tenantId,
        string $moduleKey,
        DateTimeImmutable $now,
        int $maximumSeconds,
    ): int {
        $record = $this->repository->tenantModule($tenantId, $moduleKey);
        if ($record === null) {
            return 0;
        }
        $limits = [$maximumSeconds];
        foreach ([$record->effectiveAt, $record->expiresAt] as $boundary) {
            if ($boundary !== null && $boundary > $now) {
                $limits[] = $boundary->getTimestamp() - $now->getTimestamp();
            }
        }

        return max(0, min($limits));
    }
}
