<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Module\Model\ModuleInstallation;
use PeanutAdmin\Kernel\Module\Model\TenantModule;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use think\Model;

final readonly class ModuleAvailabilityService
{
    public function assertAvailable(
        TenantScope $scope,
        string $moduleKey,
        DateTimeImmutable $now,
        bool $lock = false,
    ): void {
        $this->assertDeployment($moduleKey, $lock);
        $this->assertTenant($scope, $moduleKey, $now, $lock);
    }

    public function assertDeployment(string $moduleKey, bool $lock = false): void
    {
        $installation = ModuleInstallation::where('module_key', $moduleKey)->lock($lock)->find();
        if (!$installation instanceof ModuleInstallation) {
            throw new ModuleException('MODULE_NOT_INSTALLED', "Module {$moduleKey} is not installed.");
        }
        if ($installation->getAttr('status') !== 'active') {
            throw new ModuleException('MODULE_INSTALLATION_FAILED', "Module {$moduleKey} is not active.");
        }

    }

    public function assertTenant(
        TenantScope $scope,
        string $moduleKey,
        DateTimeImmutable $now,
        bool $lock = false,
    ): void {
        $tenantModule = TenantModule::scope('tenant', $scope)
            ->where('module_key', $moduleKey)
            ->lock($lock)
            ->find();
        if (!$tenantModule instanceof TenantModule || $tenantModule->getAttr('status') === 'disabled') {
            throw new ModuleException('MODULE_TENANT_DISABLED', "Module {$moduleKey} is disabled for tenant.");
        }
        if (!$this->effective($tenantModule, $now)) {
            throw new ModuleException('MODULE_TENANT_NOT_EFFECTIVE', "Module {$moduleKey} is outside its effective window.");
        }
    }

    private function effective(Model $record, DateTimeImmutable $now): bool
    {
        $effectiveAt = $this->date($record->getAttr('effective_at'));
        $expiresAt = $this->date($record->getAttr('expires_at'));

        return $record->getAttr('status') === 'enabled'
            && ($effectiveAt === null || $effectiveAt <= $now)
            && ($expiresAt === null || $now < $expiresAt);
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        return is_string($value) ? new DateTimeImmutable($value) : null;
    }
}
