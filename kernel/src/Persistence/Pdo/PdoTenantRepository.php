<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Pdo;

use DomainException;
use InvalidArgumentException;
use PeanutAdmin\Kernel\Tenancy\TenantRecord;
use PeanutAdmin\Kernel\Tenancy\TenantRepository;
use PeanutAdmin\Kernel\Tenancy\TenantStatus;

final class PdoTenantRepository extends PdoRepository implements TenantRepository
{
    private const CODE_PATTERN = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D';

    public function createProvisioning(string $code, string $name): TenantRecord
    {
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new InvalidArgumentException('Invalid tenant code.');
        }

        $now = $this->now();
        $this->execute(<<<'SQL'
INSERT INTO pa_tenant (code, name, display_name, created_at, updated_at)
VALUES (:code, :name, :display_name, :created_at, :updated_at)
SQL, [
            'code' => $code,
            'name' => $name,
            'display_name' => $name,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->requireTenant($this->lastInsertId());
    }

    public function byId(int $tenantId, bool $forUpdate = false): ?TenantRecord
    {
        return $this->find('id = :value', $tenantId, $forUpdate);
    }

    public function byCode(string $code, bool $forUpdate = false): ?TenantRecord
    {
        return $this->find('code = :value', $code, $forUpdate);
    }

    public function transition(int $tenantId, TenantStatus $next): TenantRecord
    {
        $current = $this->requireTenant($tenantId, true);
        $current->status->transitionTo($next);

        $now = $this->now();
        $this->execute(<<<'SQL'
UPDATE pa_tenant
SET status = :status,
    security_revision = security_revision + 1,
    revision = revision + 1,
    activated_at = CASE WHEN :activation_status = 'active' THEN :activated_at ELSE activated_at END,
    suspended_at = CASE WHEN :suspension_status = 'suspended' THEN :suspended_at ELSE suspended_at END,
    closed_at = CASE WHEN :closed_status = 'closed' THEN :closed_at ELSE closed_at END,
    updated_at = :updated_at
WHERE id = :id AND revision = :expected_revision
SQL, [
            'status' => $next->value,
            'activation_status' => $next->value,
            'suspension_status' => $next->value,
            'closed_status' => $next->value,
            'activated_at' => $now,
            'suspended_at' => $now,
            'closed_at' => $now,
            'updated_at' => $now,
            'id' => $tenantId,
            'expected_revision' => $current->revision,
        ]);

        return $this->requireTenant($tenantId);
    }

    private function requireTenant(int $tenantId, bool $forUpdate = false): TenantRecord
    {
        $tenant = $this->byId($tenantId, $forUpdate);
        if ($tenant === null) {
            throw new DomainException('Tenant was not found.');
        }

        return $tenant;
    }

    private function find(string $predicate, int|string $value, bool $forUpdate): ?TenantRecord
    {
        $row = $this->fetchOne(
            'SELECT id, code, name, status, security_revision, authorization_revision, revision'
            . " FROM pa_tenant WHERE {$predicate}"
            . ($forUpdate ? ' FOR UPDATE' : ''),
            ['value' => $value],
        );

        return $row === null ? null : new TenantRecord(
            (int) $row['id'],
            (string) $row['code'],
            (string) $row['name'],
            TenantStatus::from((string) $row['status']),
            (int) $row['security_revision'],
            (int) $row['authorization_revision'],
            (int) $row['revision'],
        );
    }
}
