<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module\Persistence;

use DateTimeImmutable;
use PDO;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleInstallationRecord;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Kernel\Module\TenantModuleMutationRepository;
use PeanutAdmin\Kernel\Module\TenantModuleRecord;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoRepository;

final class PdoModuleRuntimeRepository extends PdoRepository implements ModuleRuntimeRepository, TenantModuleMutationRepository
{
    public function __construct(PDO $pdo, private readonly bool $lockAvailabilityReads = false)
    {
        parent::__construct($pdo);
    }

    public function tenantIsActive(int $tenantId): bool
    {
        $row = $this->fetchOne('SELECT status FROM pa_tenant WHERE id = :tenant_id', ['tenant_id' => $tenantId]);

        return $row !== null && $row['status'] === 'active';
    }

    public function installation(string $moduleKey): ?ModuleInstallationRecord
    {
        $row = $this->fetchOne(<<<SQL
SELECT module_key, installed_version, status, revision, manifest_digest
FROM pa_module_installation WHERE module_key = :module_key{$this->availabilityLockClause()}
SQL, ['module_key' => $moduleKey]);

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
        $row = $this->fetchOne(<<<SQL
SELECT tenant_id, module_key, status, effective_at, expires_at, authorization_revision
FROM pa_tenant_module WHERE tenant_id = :tenant_id AND module_key = :module_key{$this->availabilityLockClause()}
SQL, ['tenant_id' => $tenantId, 'module_key' => $moduleKey]);

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
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT module_key FROM pa_tenant_module
WHERE tenant_id = :tenant_id AND status = 'enabled'
ORDER BY module_key
SQL);
        $statement->execute(['tenant_id' => $tenantId]);

        return array_values(array_filter(
            array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN)),
            static fn(string $key): bool => $key !== $moduleKey,
        ));
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
        $timestamp = $now->format('Y-m-d H:i:s.v');
        $this->execute(<<<'SQL'
INSERT INTO pa_tenant_module (
    tenant_id, module_key, status, source, config_json, config_revision,
    authorization_revision, effective_at, expires_at, enabled_at, created_at, updated_at
) VALUES (
    :tenant_id, :module_key, 'enabled', :source, :config_json, 1, 1,
    :effective_at, :expires_at, :enabled_at, :created_at, :updated_at
)
ON DUPLICATE KEY UPDATE
    status = 'enabled', source = VALUES(source), config_json = VALUES(config_json),
    config_revision = config_revision + 1,
    authorization_revision = authorization_revision + 1,
    effective_at = VALUES(effective_at), expires_at = VALUES(expires_at),
    enabled_at = VALUES(enabled_at), disabled_at = NULL, disabled_reason = NULL,
    updated_at = VALUES(updated_at)
SQL, [
            'tenant_id' => $tenantId,
            'module_key' => $moduleKey,
            'source' => $source,
            'config_json' => json_encode($config, JSON_THROW_ON_ERROR),
            'effective_at' => $effectiveAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'),
            'expires_at' => $expiresAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'),
            'enabled_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $this->execute(<<<'SQL'
UPDATE pa_tenant SET authorization_revision = authorization_revision + 1,
revision = revision + 1, updated_at = :updated_at WHERE id = :tenant_id
SQL, ['updated_at' => $timestamp, 'tenant_id' => $tenantId]);

        return $this->tenantModule($tenantId, $moduleKey)
            ?? throw new ModuleException('MODULE_TENANT_DISABLED', 'Enabled module could not be reloaded.');
    }

    public function disable(int $tenantId, string $moduleKey, DateTimeImmutable $now): TenantModuleRecord
    {
        $timestamp = $now->format('Y-m-d H:i:s.v');
        $this->execute(<<<'SQL'
UPDATE pa_tenant_module SET status = 'disabled', disabled_at = :disabled_at,
authorization_revision = authorization_revision + 1, updated_at = :updated_at
WHERE tenant_id = :tenant_id AND module_key = :module_key
SQL, [
            'disabled_at' => $timestamp,
            'updated_at' => $timestamp,
            'tenant_id' => $tenantId,
            'module_key' => $moduleKey,
        ]);
        $this->execute(<<<'SQL'
UPDATE pa_tenant SET authorization_revision = authorization_revision + 1,
revision = revision + 1, updated_at = :updated_at WHERE id = :tenant_id
SQL, ['updated_at' => $timestamp, 'tenant_id' => $tenantId]);

        return $this->tenantModule($tenantId, $moduleKey)
            ?? throw new ModuleException('MODULE_TENANT_DISABLED', 'Disabled module could not be reloaded.');
    }

    private function availabilityLockClause(): string
    {
        return $this->lockAvailabilityReads ? ' FOR SHARE' : '';
    }
}
