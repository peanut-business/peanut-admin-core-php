<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Membership\Application;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Kernel\Identity\EmailAddress;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use Throwable;

final readonly class MemberAdminService
{
    public function __construct(
        private PDO $pdo,
        private PasswordHasher $passwords = new PasswordHasher(),
    ) {}

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function list(int $tenantId, PageRequest $page): array
    {
        $total = $this->scalar(
            'SELECT COUNT(*) FROM pa_tenant_member WHERE tenant_id = :tenant_id',
            ['tenant_id' => $tenantId],
        );
        $statement = $this->statement(<<<'SQL'
SELECT tm.id, tm.display_name, tm.member_no, tm.member_type, tm.primary_department_id,
       tm.status, tm.security_revision, tm.authorization_revision,
       GROUP_CONCAT(r.`key` ORDER BY r.`key` SEPARATOR ',') AS role_keys
FROM pa_tenant_member tm
LEFT JOIN pa_member_role mr ON mr.tenant_id = tm.tenant_id AND mr.tenant_member_id = tm.id
LEFT JOIN pa_role r ON r.tenant_id = mr.tenant_id AND r.id = mr.role_id
WHERE tm.tenant_id = :tenant_id
GROUP BY tm.id
ORDER BY tm.id
LIMIT :limit OFFSET :offset
SQL);
        $statement->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $page->pageSize, PDO::PARAM_INT);
        $statement->bindValue(':offset', $page->offset(), PDO::PARAM_INT);
        $statement->execute();

        return ['items' => $this->memberRows($statement), 'total' => $total];
    }

    /** @return array<string, mixed> */
    public function get(int $tenantId, int $memberId): array
    {
        $statement = $this->statement(<<<'SQL'
SELECT tm.id, tm.display_name, tm.member_no, tm.member_type, tm.primary_department_id,
       tm.status, tm.security_revision, tm.authorization_revision,
       GROUP_CONCAT(r.`key` ORDER BY r.`key` SEPARATOR ',') AS role_keys
FROM pa_tenant_member tm
LEFT JOIN pa_member_role mr ON mr.tenant_id = tm.tenant_id AND mr.tenant_member_id = tm.id
LEFT JOIN pa_role r ON r.tenant_id = mr.tenant_id AND r.id = mr.role_id
WHERE tm.tenant_id = :tenant_id AND tm.id = :member_id
GROUP BY tm.id
SQL);
        $statement->execute(['tenant_id' => $tenantId, 'member_id' => $memberId]);
        $rows = $this->memberRows($statement);

        return $rows[0] ?? throw AdminAccessException::notFound();
    }

    /** @return array<string, mixed> */
    public function createPending(
        int $tenantId,
        string $email,
        string $displayName,
        ?string $initialPassword,
        int $actorMemberId,
        int $actorAccountId,
        string $requestId,
    ): array {
        try {
            $normalizedEmail = EmailAddress::fromString($email)->value();
        } catch (InvalidArgumentException) {
            throw AdminAccessException::invalid('EMAIL_INVALID', 'The email address is invalid.');
        }
        $identifier = $normalizedEmail;

        return $this->transaction(function () use (
            $tenantId,
            $identifier,
            $displayName,
            $initialPassword,
            $actorMemberId,
            $actorAccountId,
            $requestId,
        ): array {
            $this->requireTenantStatus($tenantId, 'active', true);
            $credential = $this->fetchOne(
                "SELECT id, account_id, status FROM pa_credential WHERE identifier_type = 'email' AND identifier_normalized = :identifier FOR UPDATE",
                ['identifier' => $identifier],
            );
            if ($credential === null) {
                if ($initialPassword === null || $initialPassword === '') {
                    throw AdminAccessException::invalid(
                        'INITIAL_PASSWORD_REQUIRED',
                        'An initial password is required for a new account.',
                    );
                }
                $accountId = $this->createAccountAndCredential($identifier, $displayName, $initialPassword);
            } else {
                if ($initialPassword !== null) {
                    throw AdminAccessException::invalid(
                        'INITIAL_PASSWORD_NOT_ALLOWED',
                        'An existing account credential cannot be overwritten.',
                    );
                }
                if ($credential['status'] !== 'active') {
                    throw AdminAccessException::conflict('CREDENTIAL_INACTIVE', 'The account credential is inactive.');
                }
                $accountId = (int) $credential['account_id'];
                $account = $this->fetchOne('SELECT status FROM pa_account WHERE id = :id FOR UPDATE', ['id' => $accountId]);
                if ($account === null || $account['status'] !== 'active') {
                    throw AdminAccessException::conflict('ACCOUNT_INACTIVE', 'The account is inactive.');
                }
            }

            $existing = $this->fetchOne(
                'SELECT id, status FROM pa_tenant_member WHERE tenant_id = :tenant_id AND account_id = :account_id FOR UPDATE',
                ['tenant_id' => $tenantId, 'account_id' => $accountId],
            );
            if ($existing !== null && $existing['status'] !== 'left') {
                throw AdminAccessException::conflict('MEMBER_ALREADY_EXISTS', 'The account is already a tenant member.');
            }

            $now = $this->now();
            if ($existing === null) {
                $this->execute(<<<'SQL'
INSERT INTO pa_tenant_member (tenant_id, account_id, display_name, status, created_at, updated_at)
VALUES (:tenant_id, :account_id, :display_name, 'pending', :created_at, :updated_at)
SQL, [
                    'tenant_id' => $tenantId,
                    'account_id' => $accountId,
                    'display_name' => $displayName,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $memberId = (int) $this->pdo->lastInsertId();
            } else {
                $memberId = (int) $existing['id'];
                $this->execute(<<<'SQL'
UPDATE pa_tenant_member
SET display_name = :display_name, status = 'pending', primary_department_id = NULL,
    security_revision = security_revision + 1,
    authorization_revision = authorization_revision + 1,
    joined_at = NULL, suspended_at = NULL, left_at = NULL, updated_at = :updated_at
WHERE tenant_id = :tenant_id AND id = :member_id
SQL, [
                    'display_name' => $displayName,
                    'updated_at' => $now,
                    'tenant_id' => $tenantId,
                    'member_id' => $memberId,
                ]);
                $this->execute(
                    'DELETE FROM pa_member_role WHERE tenant_id = :tenant_id AND tenant_member_id = :member_id',
                    ['tenant_id' => $tenantId, 'member_id' => $memberId],
                );
            }
            $this->bumpTenantAuthorization($tenantId, $now);
            $this->audit(
                $tenantId,
                $actorMemberId,
                $actorAccountId,
                'tenant.member.pending-created',
                'core.member.create',
                'member',
                $memberId,
                $requestId,
            );

            return $this->get($tenantId, $memberId);
        });
    }

    /** @return array<string, mixed> */
    public function update(
        int $tenantId,
        int $memberId,
        ?string $displayName,
        ?int $primaryDepartmentId,
        int $expectedRevision,
        int $actorMemberId,
        int $actorAccountId,
        string $requestId,
    ): array {
        return $this->transaction(function () use (
            $tenantId,
            $memberId,
            $displayName,
            $primaryDepartmentId,
            $expectedRevision,
            $actorMemberId,
            $actorAccountId,
            $requestId,
        ): array {
            $member = $this->requireMember($tenantId, $memberId, true);
            if ((int) $member['authorization_revision'] !== $expectedRevision) {
                throw AdminAccessException::revisionMismatch();
            }
            if ($primaryDepartmentId !== null) {
                $department = $this->fetchOne(
                    "SELECT id FROM pa_department WHERE tenant_id = :tenant_id AND id = :department_id AND status = 'active'",
                    ['tenant_id' => $tenantId, 'department_id' => $primaryDepartmentId],
                );
                if ($department === null) {
                    throw AdminAccessException::notFound();
                }
            }
            $now = $this->now();
            $updated = $this->execute(<<<'SQL'
UPDATE pa_tenant_member
SET display_name = :display_name,
    primary_department_id = :department_id,
    authorization_revision = authorization_revision + 1,
    updated_at = :updated_at
WHERE tenant_id = :tenant_id AND id = :member_id AND authorization_revision = :expected_revision
SQL, [
                'display_name' => $displayName,
                'department_id' => $primaryDepartmentId,
                'updated_at' => $now,
                'tenant_id' => $tenantId,
                'member_id' => $memberId,
                'expected_revision' => $expectedRevision,
            ]);
            if ($updated !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $this->bumpTenantAuthorization($tenantId, $now);
            $this->audit($tenantId, $actorMemberId, $actorAccountId, 'tenant.member.updated', 'core.member.update', 'member', $memberId, $requestId);

            return $this->get($tenantId, $memberId);
        });
    }

    /** @return array<string, mixed> */
    public function activate(
        int $tenantId,
        int $memberId,
        int $expectedRevision,
        int $actorMemberId,
        int $actorAccountId,
        string $requestId,
    ): array {
        return $this->transition(
            $tenantId,
            $memberId,
            ['pending', 'suspended'],
            'active',
            $expectedRevision,
            $actorMemberId,
            $actorAccountId,
            $requestId,
            'core.member.activate',
        );
    }

    /** @return array<string, mixed> */
    public function suspend(
        int $tenantId,
        int $memberId,
        int $expectedRevision,
        int $actorMemberId,
        int $actorAccountId,
        string $requestId,
    ): array {
        return $this->transition(
            $tenantId,
            $memberId,
            ['active'],
            'suspended',
            $expectedRevision,
            $actorMemberId,
            $actorAccountId,
            $requestId,
            'core.member.suspend',
        );
    }

    /** @return array<string, mixed> */
    public function leave(
        int $tenantId,
        int $memberId,
        int $expectedRevision,
        int $actorMemberId,
        int $actorAccountId,
        string $requestId,
    ): array {
        return $this->transition(
            $tenantId,
            $memberId,
            ['pending', 'active', 'suspended'],
            'left',
            $expectedRevision,
            $actorMemberId,
            $actorAccountId,
            $requestId,
            'core.member.leave',
        );
    }

    /**
     * @param list<int> $roleIds
     * @return array<string, mixed>
     */
    public function replaceRoles(
        int $tenantId,
        int $memberId,
        array $roleIds,
        int $expectedRevision,
        int $actorMemberId,
        int $actorAccountId,
        string $requestId,
    ): array {
        $roleIds = array_values(array_unique($roleIds));

        return $this->transaction(function () use (
            $tenantId,
            $memberId,
            $roleIds,
            $expectedRevision,
            $actorMemberId,
            $actorAccountId,
            $requestId,
        ): array {
            $member = $this->requireMember($tenantId, $memberId, true);
            if ((int) $member['authorization_revision'] !== $expectedRevision) {
                throw AdminAccessException::revisionMismatch();
            }
            $roles = $this->rolesByIds($tenantId, $roleIds);
            if (count($roles) !== count($roleIds)) {
                throw AdminAccessException::notFound();
            }
            $currentlyOwner = $this->memberIsOwner($tenantId, $memberId);
            $keepsOwner = false;
            foreach ($roles as $role) {
                if ($role['key'] === 'core.tenant-owner' && (int) $role['is_builtin'] === 1) {
                    $keepsOwner = true;
                    break;
                }
            }
            if ($currentlyOwner && !$keepsOwner) {
                $this->assertNotLastActiveOwner($tenantId, $memberId);
            }

            $this->execute(
                'DELETE FROM pa_member_role WHERE tenant_id = :tenant_id AND tenant_member_id = :member_id',
                ['tenant_id' => $tenantId, 'member_id' => $memberId],
            );
            $now = $this->now();
            foreach ($roleIds as $roleId) {
                $this->execute(<<<'SQL'
INSERT INTO pa_member_role (tenant_id, tenant_member_id, role_id, assigned_by_member_id, assigned_at)
VALUES (:tenant_id, :member_id, :role_id, :assigner_id, :assigned_at)
SQL, [
                    'tenant_id' => $tenantId,
                    'member_id' => $memberId,
                    'role_id' => $roleId,
                    'assigner_id' => $actorMemberId,
                    'assigned_at' => $now,
                ]);
            }
            $this->execute(<<<'SQL'
UPDATE pa_tenant_member
SET authorization_revision = authorization_revision + 1, updated_at = :updated_at
WHERE tenant_id = :tenant_id AND id = :member_id AND authorization_revision = :expected_revision
SQL, [
                'updated_at' => $now,
                'tenant_id' => $tenantId,
                'member_id' => $memberId,
                'expected_revision' => $expectedRevision,
            ]);
            $this->bumpTenantAuthorization($tenantId, $now);
            $this->audit($tenantId, $actorMemberId, $actorAccountId, 'tenant.member.roles-replaced', 'core.member.role.assign', 'member', $memberId, $requestId);

            return $this->get($tenantId, $memberId);
        });
    }

    /**
     * @param list<string> $fromStatuses
     * @return array<string, mixed>
     */
    private function transition(
        int $tenantId,
        int $memberId,
        array $fromStatuses,
        string $nextStatus,
        int $expectedRevision,
        int $actorMemberId,
        int $actorAccountId,
        string $requestId,
        string $action,
    ): array {
        return $this->transaction(function () use (
            $tenantId,
            $memberId,
            $fromStatuses,
            $nextStatus,
            $expectedRevision,
            $actorMemberId,
            $actorAccountId,
            $requestId,
            $action,
        ): array {
            $this->requireTenantStatus($tenantId, 'active', true);
            $member = $this->requireMember($tenantId, $memberId, true);
            if ((int) $member['authorization_revision'] !== $expectedRevision) {
                throw AdminAccessException::revisionMismatch();
            }
            if (!in_array($member['status'], $fromStatuses, true)) {
                throw AdminAccessException::conflict('MEMBER_STATUS_CONFLICT', 'The member status transition is not allowed.');
            }
            if (in_array($nextStatus, ['suspended', 'left'], true) && $this->memberIsOwner($tenantId, $memberId)) {
                $this->assertNotLastActiveOwner($tenantId, $memberId);
            }

            $now = $this->now();
            $updated = $this->execute(<<<'SQL'
UPDATE pa_tenant_member
SET status = :next_status,
    security_revision = security_revision + 1,
    authorization_revision = authorization_revision + 1,
    joined_at = CASE WHEN :active_status = 'active' THEN :joined_at ELSE joined_at END,
    suspended_at = CASE WHEN :suspended_status = 'suspended' THEN :suspended_at ELSE suspended_at END,
    left_at = CASE WHEN :left_status = 'left' THEN :left_at ELSE left_at END,
    updated_at = :updated_at
WHERE tenant_id = :tenant_id AND id = :member_id AND authorization_revision = :expected_revision
SQL, [
                'next_status' => $nextStatus,
                'active_status' => $nextStatus,
                'joined_at' => $now,
                'suspended_status' => $nextStatus,
                'suspended_at' => $now,
                'left_status' => $nextStatus,
                'left_at' => $now,
                'updated_at' => $now,
                'tenant_id' => $tenantId,
                'member_id' => $memberId,
                'expected_revision' => $expectedRevision,
            ]);
            if ($updated !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $this->bumpTenantAuthorization($tenantId, $now);
            $this->audit($tenantId, $actorMemberId, $actorAccountId, 'tenant.member.' . $nextStatus, $action, 'member', $memberId, $requestId);

            return $this->get($tenantId, $memberId);
        });
    }

    private function createAccountAndCredential(string $identifier, string $displayName, string $password): int
    {
        $now = $this->now();
        $this->execute(
            'INSERT INTO pa_account (display_name, created_at, updated_at) VALUES (:name, :created_at, :updated_at)',
            ['name' => $displayName, 'created_at' => $now, 'updated_at' => $now],
        );
        $accountId = (int) $this->pdo->lastInsertId();
        $this->execute(<<<'SQL'
INSERT INTO pa_credential (
    account_id, kind, identifier_type, identifier_normalized, secret_hash,
    verified_at, secret_changed_at, created_at, updated_at
) VALUES (
    :account_id, 'email_password', 'email', :identifier, :secret_hash,
    :verified_at, :secret_changed_at, :created_at, :updated_at
)
SQL, [
            'account_id' => $accountId,
            'identifier' => $identifier,
            'secret_hash' => $this->passwords->hash($password),
            'verified_at' => $now,
            'secret_changed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $accountId;
    }

    /** @return array<string, mixed> */
    private function requireMember(int $tenantId, int $memberId, bool $forUpdate): array
    {
        return $this->fetchOne(
            'SELECT * FROM pa_tenant_member WHERE tenant_id = :tenant_id AND id = :member_id'
            . ($forUpdate ? ' FOR UPDATE' : ''),
            ['tenant_id' => $tenantId, 'member_id' => $memberId],
        ) ?? throw AdminAccessException::notFound();
    }

    private function requireTenantStatus(int $tenantId, string $status, bool $forUpdate): void
    {
        $tenant = $this->fetchOne(
            'SELECT status FROM pa_tenant WHERE id = :tenant_id' . ($forUpdate ? ' FOR UPDATE' : ''),
            ['tenant_id' => $tenantId],
        );
        if ($tenant === null || $tenant['status'] !== $status) {
            throw new AdminAccessException('TENANT_STATUS_INVALID', 403, 'The tenant status does not allow this operation.');
        }
    }

    private function memberIsOwner(int $tenantId, int $memberId): bool
    {
        return $this->fetchOne(<<<'SQL'
SELECT mr.id
FROM pa_member_role mr
JOIN pa_role r ON r.tenant_id = mr.tenant_id AND r.id = mr.role_id
WHERE mr.tenant_id = :tenant_id AND mr.tenant_member_id = :member_id
  AND r.`key` = 'core.tenant-owner' AND r.is_builtin = 1 AND r.status = 'active'
LIMIT 1
SQL, ['tenant_id' => $tenantId, 'member_id' => $memberId]) !== null;
    }

    private function assertNotLastActiveOwner(int $tenantId, int $memberId): void
    {
        $otherOwners = $this->scalar(<<<'SQL'
SELECT COUNT(DISTINCT tm.id)
FROM pa_tenant_member tm
JOIN pa_member_role mr ON mr.tenant_id = tm.tenant_id AND mr.tenant_member_id = tm.id
JOIN pa_role r ON r.tenant_id = mr.tenant_id AND r.id = mr.role_id
WHERE tm.tenant_id = :tenant_id AND tm.status = 'active' AND tm.id <> :member_id
  AND r.`key` = 'core.tenant-owner' AND r.is_builtin = 1 AND r.status = 'active'
SQL, ['tenant_id' => $tenantId, 'member_id' => $memberId]);
        if ($otherOwners === 0) {
            throw AdminAccessException::conflict(
                'LAST_ACTIVE_OWNER_REQUIRED',
                'The final active tenant owner cannot be removed or suspended.',
            );
        }
    }

    /**
     * @param list<int> $roleIds
     * @return list<array{key: string, is_builtin: int}>
     */
    private function rolesByIds(int $tenantId, array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($roleIds), '?'));
        $statement = $this->statement(
            "SELECT id, `key`, is_builtin FROM pa_role WHERE tenant_id = ? AND status = 'active' AND id IN ({$placeholders})",
        );
        $statement->execute([$tenantId, ...$roleIds]);

        /** @var list<array{key: string, is_builtin: int}> $roles */
        $roles = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $roles;
    }

    /** @return list<array<string, mixed>> */
    private function memberRows(PDOStatement $statement): array
    {
        $items = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $roleKeys = is_string($row['role_keys']) && $row['role_keys'] !== ''
                ? explode(',', $row['role_keys'])
                : [];
            $items[] = [
                'id' => (string) $row['id'],
                'display_name' => $row['display_name'],
                'member_no' => $row['member_no'],
                'member_type' => $row['member_type'],
                'primary_department_id' => $row['primary_department_id'] === null ? null : (string) $row['primary_department_id'],
                'status' => $row['status'],
                'security_revision' => (string) $row['security_revision'],
                'revision' => (string) $row['authorization_revision'],
                'role_keys' => $roleKeys,
            ];
        }

        return $items;
    }

    private function bumpTenantAuthorization(int $tenantId, string $now): void
    {
        $this->execute(<<<'SQL'
UPDATE pa_tenant SET authorization_revision = authorization_revision + 1, updated_at = :updated_at WHERE id = :tenant_id
SQL, ['updated_at' => $now, 'tenant_id' => $tenantId]);
    }

    private function audit(
        int $tenantId,
        int $actorMemberId,
        int $actorAccountId,
        string $eventType,
        string $action,
        string $targetType,
        int $targetId,
        string $requestId,
    ): void {
        $this->execute(<<<'SQL'
INSERT INTO pa_tenant_audit_event (
    tenant_id, event_type, action, outcome, actor_tenant_id, actor_tenant_member_id,
    actor_account_id, actor_type, target_resource_type, target_resource_id,
    target_count, request_id, occurred_at
) VALUES (
    :tenant_id, :event_type, :action, 'success', :actor_tenant_id, :actor_member_id,
    :actor_account_id, 'member', :target_type, :target_id,
    1, :request_id, :occurred_at
)
SQL, [
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'action' => $action,
            'actor_tenant_id' => $tenantId,
            'actor_member_id' => $actorMemberId,
            'actor_account_id' => $actorAccountId,
            'target_type' => $targetType,
            'target_id' => (string) $targetId,
            'request_id' => $requestId,
            'occurred_at' => $this->now(),
        ]);
    }

    /** @param array<string, int|string|null> $parameters */
    private function execute(string $sql, array $parameters = []): int
    {
        $statement = $this->statement($sql);
        $statement->execute($parameters);

        return $statement->rowCount();
    }

    /**
     * @param array<string, int|string|null> $parameters
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $sql, array $parameters = []): ?array
    {
        $statement = $this->statement($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, int|string|null> $parameters */
    private function scalar(string $sql, array $parameters = []): int
    {
        $statement = $this->statement($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    private function statement(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new AdminAccessException('DATABASE_ERROR', 500, 'Could not prepare the database operation.');
        }

        return $statement;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function transaction(callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                throw AdminAccessException::conflict('RELATION_CONFLICT', 'The requested relation already exists.');
            }

            throw $exception;
        }
    }
}
