<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleInstallationRecord;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Kernel\Module\TenantModuleMutationRepository;
use PeanutAdmin\Kernel\Module\TenantModuleRecord;
use think\facade\Db;

final readonly class ThinkPhpModuleRuntimeRepository implements ModuleRuntimeRepository, TenantModuleMutationRepository
{
    public function __construct(private bool $lockAvailabilityReads = false) {}

    public function tenantIsActive(int $tenantId): bool
    {
        return Db::name('tenant')->where('id', $tenantId)->value('status') === 'active';
    }

    public function installation(string $moduleKey): ?ModuleInstallationRecord
    {
        $query = Db::name('module_installation')->where('module_key', $moduleKey);
        if ($this->lockAvailabilityReads) {
            $query->lock(true);
        }
        $row = $query->field('module_key,installed_version,status,revision,manifest_digest')->find();

        return $row === null ? null : new ModuleInstallationRecord(
            (string) $row['module_key'],
            (string) $row['installed_version'],
            (string) $row['status'],
            (int) $row['revision'],
            (string) $row['manifest_digest'],
        );
    }

    public function tenantModule(int $tenantId, string $moduleKey): ?TenantModuleRecord
    {
        $query = Db::name('tenant_module')->where('tenant_id', $tenantId)->where('module_key', $moduleKey);
        if ($this->lockAvailabilityReads) {
            $query->lock(true);
        }
        $row = $query->field(
            'tenant_id,module_key,status,effective_at,expires_at,authorization_revision',
        )->find();

        return $row === null ? null : new TenantModuleRecord(
            (int) $row['tenant_id'],
            (string) $row['module_key'],
            (string) $row['status'],
            $row['effective_at'] === null ? null : new DateTimeImmutable((string) $row['effective_at']),
            $row['expires_at'] === null ? null : new DateTimeImmutable((string) $row['expires_at']),
            (int) $row['authorization_revision'],
        );
    }

    public function enabledDependents(int $tenantId, string $moduleKey): array
    {
        return array_values(array_map('strval', Db::name('tenant_module')
            ->where('tenant_id', $tenantId)
            ->where('status', 'enabled')
            ->where('module_key', '<>', $moduleKey)
            ->order('module_key')
            ->column('module_key')));
    }

    public function enable(
        int $tenantId,
        string $moduleKey,
        array $config,
        DateTimeImmutable $now,
        string $source = 'manual',
        ?DateTimeImmutable $effectiveAt = null,
        ?DateTimeImmutable $expiresAt = null,
    ): TenantModuleRecord {
        $timestamp = $this->format($now);
        $data = [
            'status' => 'enabled',
            'source' => $source,
            'config_json' => json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'effective_at' => $effectiveAt === null ? null : $this->format($effectiveAt),
            'expires_at' => $expiresAt === null ? null : $this->format($expiresAt),
            'enabled_at' => $timestamp,
            'disabled_at' => null,
            'disabled_reason' => null,
            'updated_at' => $timestamp,
        ];
        $existing = Db::name('tenant_module')
            ->where('tenant_id', $tenantId)
            ->where('module_key', $moduleKey)
            ->lock(true)
            ->field('config_revision,authorization_revision')
            ->find();
        if ($existing === null) {
            Db::name('tenant_module')->insert([
                'tenant_id' => $tenantId,
                'module_key' => $moduleKey,
                ...$data,
                'config_revision' => 1,
                'authorization_revision' => 1,
                'created_at' => $timestamp,
            ]);
        } else {
            Db::name('tenant_module')->where('tenant_id', $tenantId)->where('module_key', $moduleKey)->update([
                ...$data,
                'config_revision' => Db::raw('config_revision + 1'),
                'authorization_revision' => Db::raw('authorization_revision + 1'),
            ]);
        }
        $this->bumpTenant($tenantId, $timestamp);

        return $this->tenantModule($tenantId, $moduleKey)
            ?? throw new ModuleException('MODULE_TENANT_DISABLED', 'Enabled module could not be reloaded.');
    }

    public function disable(int $tenantId, string $moduleKey, DateTimeImmutable $now): TenantModuleRecord
    {
        $timestamp = $this->format($now);
        Db::name('tenant_module')->where('tenant_id', $tenantId)->where('module_key', $moduleKey)->update([
            'status' => 'disabled',
            'disabled_at' => $timestamp,
            'authorization_revision' => Db::raw('authorization_revision + 1'),
            'updated_at' => $timestamp,
        ]);
        $this->bumpTenant($tenantId, $timestamp);

        return $this->tenantModule($tenantId, $moduleKey)
            ?? throw new ModuleException('MODULE_TENANT_DISABLED', 'Disabled module could not be reloaded.');
    }

    private function bumpTenant(int $tenantId, string $timestamp): void
    {
        Db::name('tenant')->where('id', $tenantId)->update([
            'authorization_revision' => Db::raw('authorization_revision + 1'),
            'revision' => Db::raw('revision + 1'),
            'updated_at' => $timestamp,
        ]);
    }

    private function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
