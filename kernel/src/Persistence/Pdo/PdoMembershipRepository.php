<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Pdo;

use DomainException;
use PeanutAdmin\Kernel\Membership\MembershipRepository;
use PeanutAdmin\Kernel\Membership\TenantMemberRecord;
use PeanutAdmin\Kernel\Membership\TenantMemberStatus;
use PeanutAdmin\Kernel\Membership\TenantRoleRecord;

final class PdoMembershipRepository extends PdoRepository implements MembershipRepository
{
    public function byId(int $tenantId, int $memberId, bool $forUpdate = false): ?TenantMemberRecord
    {
        return $this->findMember(
            'tenant_id = :tenant_id AND id = :member_id',
            ['tenant_id' => $tenantId, 'member_id' => $memberId],
            $forUpdate,
        );
    }

    public function byTenantAndAccount(
        int $tenantId,
        int $accountId,
        bool $forUpdate = false,
    ): ?TenantMemberRecord {
        return $this->findMember(
            'tenant_id = :tenant_id AND account_id = :account_id',
            ['tenant_id' => $tenantId, 'account_id' => $accountId],
            $forUpdate,
        );
    }

    public function createPending(int $tenantId, int $accountId, string $displayName): TenantMemberRecord
    {
        $now = $this->now();
        $this->execute(<<<'SQL'
INSERT INTO pa_tenant_member (
    tenant_id, account_id, display_name, status, created_at, updated_at
) VALUES (
    :tenant_id, :account_id, :display_name, 'pending', :created_at, :updated_at
)
SQL, [
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'display_name' => $displayName,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->requireMember($tenantId, $this->lastInsertId());
    }

    public function transition(
        int $tenantId,
        int $memberId,
        TenantMemberStatus $next,
    ): TenantMemberRecord {
        $current = $this->requireMember($tenantId, $memberId, true);
        $current->status->transitionTo($next);

        $now = $this->now();
        $this->execute(<<<'SQL'
UPDATE pa_tenant_member
SET status = :status,
    security_revision = security_revision + 1,
    authorization_revision = authorization_revision + 1,
    joined_at = CASE WHEN :active_status = 'active' THEN :joined_at ELSE joined_at END,
    suspended_at = CASE WHEN :suspended_status = 'suspended' THEN :suspended_at ELSE suspended_at END,
    left_at = CASE WHEN :left_status = 'left' THEN :left_at ELSE left_at END,
    updated_at = :updated_at
WHERE tenant_id = :tenant_id AND id = :member_id
  AND security_revision = :expected_revision
SQL, [
            'status' => $next->value,
            'active_status' => $next->value,
            'suspended_status' => $next->value,
            'left_status' => $next->value,
            'joined_at' => $now,
            'suspended_at' => $now,
            'left_at' => $now,
            'updated_at' => $now,
            'tenant_id' => $tenantId,
            'member_id' => $memberId,
            'expected_revision' => $current->securityRevision,
        ]);

        return $this->requireMember($tenantId, $memberId);
    }

    public function createBuiltinRole(int $tenantId, string $key, string $name): TenantRoleRecord
    {
        $now = $this->now();
        $this->execute(<<<'SQL'
INSERT INTO pa_role (
    tenant_id, `key`, name, is_builtin, created_at, updated_at
) VALUES (
    :tenant_id, :role_key, :name, 1, :created_at, :updated_at
)
SQL, [
            'tenant_id' => $tenantId,
            'role_key' => $key,
            'name' => $name,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $role = $this->roleById($tenantId, $this->lastInsertId());
        if ($role === null) {
            throw new DomainException('Tenant role could not be created.');
        }

        return $role;
    }

    public function roleByKey(int $tenantId, string $key, bool $forUpdate = false): ?TenantRoleRecord
    {
        $row = $this->fetchOne(
            'SELECT id, tenant_id, `key`, is_builtin FROM pa_role'
            . ' WHERE tenant_id = :tenant_id AND `key` = :role_key'
            . ($forUpdate ? ' FOR UPDATE' : ''),
            ['tenant_id' => $tenantId, 'role_key' => $key],
        );

        return $row === null ? null : $this->roleRecord($row);
    }

    public function assignRole(int $tenantId, int $memberId, int $roleId): void
    {
        $now = $this->now();
        $this->execute(<<<'SQL'
INSERT INTO pa_member_role (tenant_id, tenant_member_id, role_id, assigned_at)
VALUES (:tenant_id, :member_id, :role_id, :assigned_at)
SQL, [
            'tenant_id' => $tenantId,
            'member_id' => $memberId,
            'role_id' => $roleId,
            'assigned_at' => $now,
        ]);
        $this->execute(<<<'SQL'
UPDATE pa_tenant_member
SET authorization_revision = authorization_revision + 1, updated_at = :updated_at
WHERE tenant_id = :tenant_id AND id = :member_id
SQL, [
            'updated_at' => $now,
            'tenant_id' => $tenantId,
            'member_id' => $memberId,
        ]);
    }

    public function memberHasRole(int $tenantId, int $memberId, string $roleKey): bool
    {
        $row = $this->fetchOne(<<<'SQL'
SELECT mr.id
FROM pa_member_role mr
JOIN pa_role r ON r.tenant_id = mr.tenant_id AND r.id = mr.role_id
WHERE mr.tenant_id = :tenant_id
  AND mr.tenant_member_id = :member_id
  AND r.`key` = :role_key
  AND r.status = 'active'
LIMIT 1
SQL, [
            'tenant_id' => $tenantId,
            'member_id' => $memberId,
            'role_key' => $roleKey,
        ]);

        return $row !== null;
    }

    public function activeMemberWithRoleExists(int $tenantId, string $roleKey): bool
    {
        return $this->memberWithRoleInStatusesExists($tenantId, $roleKey, ['active']);
    }

    public function pendingOrActiveMemberWithRoleExists(int $tenantId, string $roleKey): bool
    {
        return $this->memberWithRoleInStatusesExists($tenantId, $roleKey, ['pending', 'active']);
    }

    /** @param non-empty-list<string> $statuses */
    private function memberWithRoleInStatusesExists(
        int $tenantId,
        string $roleKey,
        array $statuses,
    ): bool {
        $quotedStatuses = implode(', ', array_fill(0, count($statuses), '?'));
        $statement = $this->pdo->prepare(<<<SQL
SELECT tm.id
FROM pa_tenant_member tm
JOIN pa_member_role mr ON mr.tenant_id = tm.tenant_id AND mr.tenant_member_id = tm.id
JOIN pa_role r ON r.tenant_id = mr.tenant_id AND r.id = mr.role_id
WHERE tm.tenant_id = ?
  AND tm.status IN ({$quotedStatuses})
  AND r.`key` = ?
  AND r.status = 'active'
LIMIT 1
SQL);
        if ($statement === false) {
            throw new DomainException('Could not prepare owner lookup.');
        }
        $statement->execute([$tenantId, ...$statuses, $roleKey]);

        return $statement->fetchColumn() !== false;
    }

    /** @param array<string, int> $parameters */
    private function findMember(
        string $predicate,
        array $parameters,
        bool $forUpdate,
    ): ?TenantMemberRecord {
        $row = $this->fetchOne(
            'SELECT id, tenant_id, account_id, status, security_revision, authorization_revision'
            . " FROM pa_tenant_member WHERE {$predicate}"
            . ($forUpdate ? ' FOR UPDATE' : ''),
            $parameters,
        );

        return $row === null ? null : $this->memberRecord($row);
    }

    private function requireMember(
        int $tenantId,
        int $memberId,
        bool $forUpdate = false,
    ): TenantMemberRecord {
        $member = $this->byId($tenantId, $memberId, $forUpdate);
        if ($member === null) {
            throw new DomainException('Tenant member was not found.');
        }

        return $member;
    }

    private function roleById(int $tenantId, int $roleId): ?TenantRoleRecord
    {
        $row = $this->fetchOne(
            'SELECT id, tenant_id, `key`, is_builtin FROM pa_role'
            . ' WHERE tenant_id = :tenant_id AND id = :role_id',
            ['tenant_id' => $tenantId, 'role_id' => $roleId],
        );

        return $row === null ? null : $this->roleRecord($row);
    }

    /** @param array<string, mixed> $row */
    private function memberRecord(array $row): TenantMemberRecord
    {
        return new TenantMemberRecord(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['account_id'],
            TenantMemberStatus::from((string) $row['status']),
            (int) $row['security_revision'],
            (int) $row['authorization_revision'],
        );
    }

    /** @param array<string, mixed> $row */
    private function roleRecord(array $row): TenantRoleRecord
    {
        return new TenantRoleRecord(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (string) $row['key'],
            (int) $row['is_builtin'] === 1,
        );
    }
}
