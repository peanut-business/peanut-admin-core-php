<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Application;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

final readonly class RoleAdminService
{
    public function __construct(private PDO $pdo) {}

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function list(int $tenantId, PageRequest $page): array
    {
        $total = $this->scalar('SELECT COUNT(*) FROM pa_role WHERE tenant_id = :tenant_id', ['tenant_id' => $tenantId]);
        $statement = $this->statement(<<<'SQL'
SELECT r.id, r.`key`, r.name, r.description, r.is_builtin, r.status,
       r.authorization_revision, GROUP_CONCAT(p.`key` ORDER BY p.`key` SEPARATOR ',') AS permission_keys
FROM pa_role r
LEFT JOIN pa_role_permission rp ON rp.tenant_id = r.tenant_id AND rp.role_id = r.id
LEFT JOIN pa_permission p ON p.id = rp.permission_id
WHERE r.tenant_id = :tenant_id
GROUP BY r.id
ORDER BY r.id
LIMIT :limit OFFSET :offset
SQL);
        $statement->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $page->pageSize, PDO::PARAM_INT);
        $statement->bindValue(':offset', $page->offset(), PDO::PARAM_INT);
        $statement->execute();

        return ['items' => $this->rows($statement), 'total' => $total];
    }

    /** @return array<string, mixed> */
    public function get(int $tenantId, int $roleId): array
    {
        $statement = $this->statement(<<<'SQL'
SELECT r.id, r.`key`, r.name, r.description, r.is_builtin, r.status,
       r.authorization_revision, GROUP_CONCAT(p.`key` ORDER BY p.`key` SEPARATOR ',') AS permission_keys
FROM pa_role r
LEFT JOIN pa_role_permission rp ON rp.tenant_id = r.tenant_id AND rp.role_id = r.id
LEFT JOIN pa_permission p ON p.id = rp.permission_id
WHERE r.tenant_id = :tenant_id AND r.id = :role_id
GROUP BY r.id
SQL);
        $statement->execute(['tenant_id' => $tenantId, 'role_id' => $roleId]);

        return $this->rows($statement)[0] ?? throw AdminAccessException::notFound();
    }

    /** @return array<string, mixed> */
    public function create(
        int $tenantId,
        string $key,
        string $name,
        ?string $description,
        int $actorMemberId,
        int $actorAccountId,
        string $requestId,
    ): array {
        if (str_starts_with($key, 'core.') || str_starts_with($key, 'platform.')) {
            throw AdminAccessException::invalid('ROLE_KEY_RESERVED', 'The role key uses a reserved namespace.');
        }

        return $this->transaction(function () use (
            $tenantId,
            $key,
            $name,
            $description,
            $actorMemberId,
            $actorAccountId,
            $requestId,
        ): array {
            $this->lockTenant($tenantId);
            $now = $this->now();
            $this->execute(<<<'SQL'
INSERT INTO pa_role (tenant_id, `key`, name, description, is_builtin, status, created_at, updated_at)
VALUES (:tenant_id, :role_key, :name, :description, 0, 'active', :created_at, :updated_at)
SQL, [
                'tenant_id' => $tenantId,
                'role_key' => $key,
                'name' => $name,
                'description' => $description,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $roleId = (int) $this->pdo->lastInsertId();
            $this->bumpTenant($tenantId, $now);
            $this->audit($tenantId, $actorMemberId, $actorAccountId, 'tenant.role.created', 'core.role.create', $roleId, $requestId);

            return $this->get($tenantId, $roleId);
        });
    }

    /** @return array<string, mixed> */
    public function update(
        int $tenantId,
        int $roleId,
        string $name,
        ?string $description,
        int $expectedRevision,
        int $actorMemberId,
        int $actorAccountId,
        string $requestId,
    ): array {
        return $this->transaction(function () use (
            $tenantId,
            $roleId,
            $name,
            $description,
            $expectedRevision,
            $actorMemberId,
            $actorAccountId,
            $requestId,
        ): array {
            $role = $this->requireRole($tenantId, $roleId, true);
            $this->assertRevision($role, $expectedRevision);
            $now = $this->now();
            if ($this->execute(<<<'SQL'
UPDATE pa_role
SET name = :name, description = :description,
    authorization_revision = authorization_revision + 1, updated_at = :updated_at
WHERE tenant_id = :tenant_id AND id = :role_id AND authorization_revision = :expected_revision
SQL, [
                'name' => $name,
                'description' => $description,
                'updated_at' => $now,
                'tenant_id' => $tenantId,
                'role_id' => $roleId,
                'expected_revision' => $expectedRevision,
            ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $this->bumpTenant($tenantId, $now);
            $this->audit($tenantId, $actorMemberId, $actorAccountId, 'tenant.role.updated', 'core.role.update', $roleId, $requestId);

            return $this->get($tenantId, $roleId);
        });
    }

    /** @return array<string, mixed> */
    public function archive(
        int $tenantId,
        int $roleId,
        int $expectedRevision,
        int $actorMemberId,
        int $actorAccountId,
        string $requestId,
    ): array {
        return $this->transaction(function () use (
            $tenantId,
            $roleId,
            $expectedRevision,
            $actorMemberId,
            $actorAccountId,
            $requestId,
        ): array {
            $role = $this->requireRole($tenantId, $roleId, true);
            $this->assertRevision($role, $expectedRevision);
            if ((int) $role['is_builtin'] === 1) {
                throw AdminAccessException::conflict('BUILTIN_ROLE_IMMUTABLE', 'Built-in roles cannot be archived.');
            }
            if ($role['status'] === 'archived') {
                throw AdminAccessException::conflict('ROLE_ALREADY_ARCHIVED', 'The role is already archived.');
            }
            $now = $this->now();
            $this->execute(<<<'SQL'
UPDATE pa_role
SET status = 'archived', archived_at = :archived_at,
    authorization_revision = authorization_revision + 1, updated_at = :updated_at
WHERE tenant_id = :tenant_id AND id = :role_id AND authorization_revision = :expected_revision
SQL, [
                'archived_at' => $now,
                'updated_at' => $now,
                'tenant_id' => $tenantId,
                'role_id' => $roleId,
                'expected_revision' => $expectedRevision,
            ]);
            $this->bumpTenant($tenantId, $now);
            $this->audit($tenantId, $actorMemberId, $actorAccountId, 'tenant.role.archived', 'core.role.archive', $roleId, $requestId);

            return $this->get($tenantId, $roleId);
        });
    }

    /**
     * @param list<string> $permissionKeys
     * @return array<string, mixed>
     */
    public function replacePermissions(
        int $tenantId,
        int $roleId,
        array $permissionKeys,
        int $expectedRevision,
        int $actorMemberId,
        int $actorAccountId,
        string $requestId,
    ): array {
        $permissionKeys = array_values(array_unique($permissionKeys));

        return $this->transaction(function () use (
            $tenantId,
            $roleId,
            $permissionKeys,
            $expectedRevision,
            $actorMemberId,
            $actorAccountId,
            $requestId,
        ): array {
            $role = $this->requireRole($tenantId, $roleId, true);
            $this->assertRevision($role, $expectedRevision);
            if ((int) $role['is_builtin'] === 1 && $role['key'] === 'core.tenant-owner') {
                throw AdminAccessException::conflict(
                    'TENANT_OWNER_PERMISSIONS_FIXED',
                    'Tenant owner core permissions are fixed by the release catalog.',
                );
            }
            $permissions = $this->assignablePermissions($tenantId, $permissionKeys);
            if (count($permissions) !== count($permissionKeys)) {
                throw AdminAccessException::invalid(
                    'PERMISSION_NOT_ASSIGNABLE',
                    'A permission is retired, belongs to the platform, or its module is unavailable.',
                );
            }

            $this->execute(
                'DELETE FROM pa_role_permission WHERE tenant_id = :tenant_id AND role_id = :role_id',
                ['tenant_id' => $tenantId, 'role_id' => $roleId],
            );
            $now = $this->now();
            foreach ($permissions as $permission) {
                $this->execute(<<<'SQL'
INSERT INTO pa_role_permission (tenant_id, role_id, permission_id, granted_by_member_id, granted_at)
VALUES (:tenant_id, :role_id, :permission_id, :granter_id, :granted_at)
SQL, [
                    'tenant_id' => $tenantId,
                    'role_id' => $roleId,
                    'permission_id' => (int) $permission['id'],
                    'granter_id' => $actorMemberId,
                    'granted_at' => $now,
                ]);
            }
            $this->execute(<<<'SQL'
UPDATE pa_role
SET authorization_revision = authorization_revision + 1, updated_at = :updated_at
WHERE tenant_id = :tenant_id AND id = :role_id AND authorization_revision = :expected_revision
SQL, [
                'updated_at' => $now,
                'tenant_id' => $tenantId,
                'role_id' => $roleId,
                'expected_revision' => $expectedRevision,
            ]);
            $this->bumpTenant($tenantId, $now);
            $this->audit($tenantId, $actorMemberId, $actorAccountId, 'tenant.role.permissions-replaced', 'core.role.permission.assign', $roleId, $requestId);

            return $this->get($tenantId, $roleId);
        });
    }

    /** @return array<string, mixed> */
    private function requireRole(int $tenantId, int $roleId, bool $forUpdate): array
    {
        return $this->fetchOne(
            'SELECT * FROM pa_role WHERE tenant_id = :tenant_id AND id = :role_id'
            . ($forUpdate ? ' FOR UPDATE' : ''),
            ['tenant_id' => $tenantId, 'role_id' => $roleId],
        ) ?? throw AdminAccessException::notFound();
    }

    /** @param array<string, mixed> $role */
    private function assertRevision(array $role, int $expectedRevision): void
    {
        if ((int) $role['authorization_revision'] !== $expectedRevision) {
            throw AdminAccessException::revisionMismatch();
        }
    }

    /**
     * @param list<string> $permissionKeys
     * @return list<array{id: int, key: string}>
     */
    private function assignablePermissions(int $tenantId, array $permissionKeys): array
    {
        if ($permissionKeys === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($permissionKeys), '?'));
        $statement = $this->statement(<<<SQL
SELECT p.id, p.`key`
FROM pa_permission p
WHERE p.`key` IN ({$placeholders})
  AND p.status = 'active'
  AND p.`key` NOT LIKE 'platform.%'
  AND (
      p.module_key = 'core'
      OR EXISTS (
          SELECT 1 FROM pa_tenant_module tm
          WHERE tm.tenant_id = ? AND tm.module_key = p.module_key AND tm.status = 'enabled'
            AND (tm.effective_at IS NULL OR tm.effective_at <= CURRENT_TIMESTAMP(3))
            AND (tm.expires_at IS NULL OR tm.expires_at > CURRENT_TIMESTAMP(3))
      )
  )
ORDER BY p.`key`
SQL);
        $statement->execute([...$permissionKeys, $tenantId]);

        /** @var list<array{id: int, key: string}> $permissions */
        $permissions = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $permissions;
    }

    /** @return list<array<string, mixed>> */
    private function rows(PDOStatement $statement): array
    {
        $items = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $permissionKeys = is_string($row['permission_keys']) && $row['permission_keys'] !== ''
                ? explode(',', $row['permission_keys'])
                : [];
            $items[] = [
                'id' => (string) $row['id'],
                'key' => $row['key'],
                'name' => $row['name'],
                'description' => $row['description'],
                'is_builtin' => (int) $row['is_builtin'] === 1,
                'status' => $row['status'],
                'revision' => (string) $row['authorization_revision'],
                'permission_keys' => $permissionKeys,
            ];
        }

        return $items;
    }

    private function lockTenant(int $tenantId): void
    {
        if ($this->fetchOne(
            "SELECT id FROM pa_tenant WHERE id = :tenant_id AND status = 'active' FOR UPDATE",
            ['tenant_id' => $tenantId],
        ) === null) {
            throw new AdminAccessException('TENANT_STATUS_INVALID', 403, 'The tenant is not active.');
        }
    }

    private function bumpTenant(int $tenantId, string $now): void
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
        int $roleId,
        string $requestId,
    ): void {
        $this->execute(<<<'SQL'
INSERT INTO pa_tenant_audit_event (
    tenant_id, event_type, action, outcome, actor_tenant_id, actor_tenant_member_id,
    actor_account_id, actor_type, target_resource_type, target_resource_id,
    target_count, request_id, occurred_at
) VALUES (
    :tenant_id, :event_type, :action, 'success', :actor_tenant_id, :actor_member_id,
    :actor_account_id, 'member', 'role', :target_id, 1, :request_id, :occurred_at
)
SQL, [
            'tenant_id' => $tenantId,
            'actor_tenant_id' => $tenantId,
            'event_type' => $eventType,
            'action' => $action,
            'actor_member_id' => $actorMemberId,
            'actor_account_id' => $actorAccountId,
            'target_id' => (string) $roleId,
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
                throw AdminAccessException::conflict('ROLE_CONFLICT', 'Role key or relation conflicts.');
            }

            throw $exception;
        }
    }
}
