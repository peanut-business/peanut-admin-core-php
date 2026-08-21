<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Organization\Application;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use PDOStatement;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use Throwable;

final readonly class DepartmentAdminService
{
    private const MAX_DEPTH = 10;

    public function __construct(private PDO $pdo) {}

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function list(int $tenantId, PageRequest $page): array
    {
        $total = $this->scalar(
            'SELECT COUNT(*) FROM pa_department WHERE tenant_id = :tenant_id',
            ['tenant_id' => $tenantId],
        );
        $statement = $this->statement(<<<'SQL'
SELECT id, parent_id, code, name, sort_order, status, revision
FROM pa_department
WHERE tenant_id = :tenant_id
ORDER BY sort_order, id
LIMIT :limit OFFSET :offset
SQL);
        $statement->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $page->pageSize, PDO::PARAM_INT);
        $statement->bindValue(':offset', $page->offset(), PDO::PARAM_INT);
        $statement->execute();

        return ['items' => $this->rows($statement), 'total' => $total];
    }

    /** @return array<string, mixed> */
    public function get(int $tenantId, int $departmentId): array
    {
        $statement = $this->statement(<<<'SQL'
SELECT id, parent_id, code, name, sort_order, status, revision
FROM pa_department WHERE tenant_id = :tenant_id AND id = :department_id
SQL);
        $statement->execute(['tenant_id' => $tenantId, 'department_id' => $departmentId]);

        return $this->rows($statement)[0] ?? throw AdminAccessException::notFound();
    }

    /** @return array<string, mixed> */
    public function create(
        int $tenantId,
        string $code,
        string $name,
        ?int $parentId,
        int $sortOrder,
        int $actorMemberId,
        int $actorAccountId,
        string $requestId,
    ): array {
        return $this->transaction(function () use (
            $tenantId,
            $code,
            $name,
            $parentId,
            $sortOrder,
            $actorMemberId,
            $actorAccountId,
            $requestId,
        ): array {
            $this->lockTenant($tenantId);
            if ($parentId !== null) {
                $this->requireActive($tenantId, $parentId, true);
                if ($this->depth($tenantId, $parentId) >= self::MAX_DEPTH) {
                    throw AdminAccessException::invalid('DEPARTMENT_DEPTH_EXCEEDED', 'Department depth cannot exceed 10.');
                }
            }
            $now = $this->now();
            $this->execute(<<<'SQL'
INSERT INTO pa_department (
    tenant_id, parent_id, code, name, sort_order, status, created_at, updated_at
) VALUES (
    :tenant_id, :parent_id, :code, :name, :sort_order, 'active', :created_at, :updated_at
)
SQL, [
                'tenant_id' => $tenantId,
                'parent_id' => $parentId,
                'code' => $code,
                'name' => $name,
                'sort_order' => $sortOrder,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $departmentId = (int) $this->pdo->lastInsertId();
            $this->bumpTenant($tenantId, $now);
            $this->audit($tenantId, $actorMemberId, $actorAccountId, 'tenant.department.created', 'core.department.create', $departmentId, $requestId);

            return $this->get($tenantId, $departmentId);
        });
    }

    /** @return array<string, mixed> */
    public function update(
        int $tenantId,
        int $departmentId,
        string $code,
        string $name,
        int $sortOrder,
        int $expectedRevision,
        int $actorMemberId,
        int $actorAccountId,
        string $requestId,
    ): array {
        return $this->transaction(function () use (
            $tenantId,
            $departmentId,
            $code,
            $name,
            $sortOrder,
            $expectedRevision,
            $actorMemberId,
            $actorAccountId,
            $requestId,
        ): array {
            $department = $this->requireDepartment($tenantId, $departmentId, true);
            $this->assertRevision($department, $expectedRevision);
            $now = $this->now();
            if ($this->execute(<<<'SQL'
UPDATE pa_department
SET code = :code, name = :name, sort_order = :sort_order,
    revision = revision + 1, updated_at = :updated_at
WHERE tenant_id = :tenant_id AND id = :department_id AND revision = :expected_revision
SQL, [
                'code' => $code,
                'name' => $name,
                'sort_order' => $sortOrder,
                'updated_at' => $now,
                'tenant_id' => $tenantId,
                'department_id' => $departmentId,
                'expected_revision' => $expectedRevision,
            ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $this->bumpTenant($tenantId, $now);
            $this->audit($tenantId, $actorMemberId, $actorAccountId, 'tenant.department.updated', 'core.department.update', $departmentId, $requestId);

            return $this->get($tenantId, $departmentId);
        });
    }

    /** @return array<string, mixed> */
    public function move(
        int $tenantId,
        int $departmentId,
        ?int $newParentId,
        int $expectedRevision,
        int $actorMemberId,
        int $actorAccountId,
        string $requestId,
    ): array {
        return $this->transaction(function () use (
            $tenantId,
            $departmentId,
            $newParentId,
            $expectedRevision,
            $actorMemberId,
            $actorAccountId,
            $requestId,
        ): array {
            $department = $this->requireDepartment($tenantId, $departmentId, true);
            $this->assertRevision($department, $expectedRevision);
            if ($newParentId === $departmentId) {
                throw AdminAccessException::invalid('DEPARTMENT_CYCLE', 'A department cannot be its own parent.');
            }
            $parentDepth = 0;
            if ($newParentId !== null) {
                $this->requireActive($tenantId, $newParentId, true);
                if ($this->isDescendant($tenantId, $departmentId, $newParentId)) {
                    throw AdminAccessException::invalid('DEPARTMENT_CYCLE', 'Department moves cannot create a cycle.');
                }
                $parentDepth = $this->depth($tenantId, $newParentId);
            }
            if ($parentDepth + $this->subtreeDepth($tenantId, $departmentId) > self::MAX_DEPTH) {
                throw AdminAccessException::invalid('DEPARTMENT_DEPTH_EXCEEDED', 'Department depth cannot exceed 10.');
            }

            $now = $this->now();
            if ($this->execute(<<<'SQL'
UPDATE pa_department
SET parent_id = :parent_id, revision = revision + 1, updated_at = :updated_at
WHERE tenant_id = :tenant_id AND id = :department_id AND revision = :expected_revision
SQL, [
                'parent_id' => $newParentId,
                'updated_at' => $now,
                'tenant_id' => $tenantId,
                'department_id' => $departmentId,
                'expected_revision' => $expectedRevision,
            ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $this->bumpTenant($tenantId, $now);
            $this->audit($tenantId, $actorMemberId, $actorAccountId, 'tenant.department.moved', 'core.department.move', $departmentId, $requestId);

            return $this->get($tenantId, $departmentId);
        });
    }

    /** @return array<string, mixed> */
    public function archive(
        int $tenantId,
        int $departmentId,
        int $expectedRevision,
        int $actorMemberId,
        int $actorAccountId,
        string $requestId,
    ): array {
        return $this->transaction(function () use (
            $tenantId,
            $departmentId,
            $expectedRevision,
            $actorMemberId,
            $actorAccountId,
            $requestId,
        ): array {
            $department = $this->requireDepartment($tenantId, $departmentId, true);
            $this->assertRevision($department, $expectedRevision);
            if ($department['status'] === 'archived') {
                throw AdminAccessException::conflict('DEPARTMENT_ALREADY_ARCHIVED', 'The department is already archived.');
            }
            $childCount = $this->scalar(<<<'SQL'
SELECT COUNT(*) FROM pa_department
WHERE tenant_id = :tenant_id AND parent_id = :department_id AND status <> 'archived'
SQL, ['tenant_id' => $tenantId, 'department_id' => $departmentId]);
            $memberCount = $this->scalar(<<<'SQL'
SELECT COUNT(*) FROM pa_tenant_member
WHERE tenant_id = :tenant_id AND primary_department_id = :department_id AND status IN ('pending', 'active', 'suspended')
SQL, ['tenant_id' => $tenantId, 'department_id' => $departmentId]);
            if ($childCount !== 0 || $memberCount !== 0) {
                throw AdminAccessException::conflict(
                    'DEPARTMENT_NOT_EMPTY',
                    'Move child departments and current members before archiving.',
                );
            }

            $now = $this->now();
            $this->execute(<<<'SQL'
UPDATE pa_department
SET status = 'archived', archived_at = :archived_at,
    revision = revision + 1, updated_at = :updated_at
WHERE tenant_id = :tenant_id AND id = :department_id AND revision = :expected_revision
SQL, [
                'archived_at' => $now,
                'updated_at' => $now,
                'tenant_id' => $tenantId,
                'department_id' => $departmentId,
                'expected_revision' => $expectedRevision,
            ]);
            $this->bumpTenant($tenantId, $now);
            $this->audit($tenantId, $actorMemberId, $actorAccountId, 'tenant.department.archived', 'core.department.archive', $departmentId, $requestId);

            return $this->get($tenantId, $departmentId);
        });
    }

    /** @return array<string, mixed> */
    private function requireDepartment(int $tenantId, int $departmentId, bool $forUpdate): array
    {
        return $this->fetchOne(
            'SELECT * FROM pa_department WHERE tenant_id = :tenant_id AND id = :department_id'
            . ($forUpdate ? ' FOR UPDATE' : ''),
            ['tenant_id' => $tenantId, 'department_id' => $departmentId],
        ) ?? throw AdminAccessException::notFound();
    }

    private function requireActive(int $tenantId, int $departmentId, bool $forUpdate): void
    {
        $department = $this->requireDepartment($tenantId, $departmentId, $forUpdate);
        if ($department['status'] !== 'active') {
            throw AdminAccessException::invalid('DEPARTMENT_INACTIVE', 'The parent department must be active.');
        }
    }

    /** @param array<string, mixed> $department */
    private function assertRevision(array $department, int $expectedRevision): void
    {
        if ((int) $department['revision'] !== $expectedRevision) {
            throw AdminAccessException::revisionMismatch();
        }
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

    private function depth(int $tenantId, int $departmentId): int
    {
        return $this->scalar(<<<'SQL'
WITH RECURSIVE ancestors AS (
    SELECT id, parent_id, 1 AS depth FROM pa_department WHERE tenant_id = :tenant_id AND id = :department_id
    UNION ALL
    SELECT d.id, d.parent_id, ancestors.depth + 1
    FROM pa_department d JOIN ancestors ON ancestors.parent_id = d.id
    WHERE d.tenant_id = :tenant_id_recursive AND ancestors.depth < 11
)
SELECT MAX(depth) FROM ancestors
SQL, [
            'tenant_id' => $tenantId,
            'department_id' => $departmentId,
            'tenant_id_recursive' => $tenantId,
        ]);
    }

    private function subtreeDepth(int $tenantId, int $departmentId): int
    {
        return $this->scalar(<<<'SQL'
WITH RECURSIVE descendants AS (
    SELECT id, 1 AS depth FROM pa_department WHERE tenant_id = :tenant_id AND id = :department_id
    UNION ALL
    SELECT d.id, descendants.depth + 1
    FROM pa_department d JOIN descendants ON d.parent_id = descendants.id
    WHERE d.tenant_id = :tenant_id_recursive AND descendants.depth < 11
)
SELECT MAX(depth) FROM descendants
SQL, [
            'tenant_id' => $tenantId,
            'department_id' => $departmentId,
            'tenant_id_recursive' => $tenantId,
        ]);
    }

    private function isDescendant(int $tenantId, int $departmentId, int $possibleDescendantId): bool
    {
        return $this->scalar(<<<'SQL'
WITH RECURSIVE descendants AS (
    SELECT id FROM pa_department WHERE tenant_id = :tenant_id AND id = :department_id
    UNION ALL
    SELECT d.id FROM pa_department d JOIN descendants ON d.parent_id = descendants.id
    WHERE d.tenant_id = :tenant_id_recursive
)
SELECT COUNT(*) FROM descendants WHERE id = :possible_descendant_id
SQL, [
            'tenant_id' => $tenantId,
            'department_id' => $departmentId,
            'tenant_id_recursive' => $tenantId,
            'possible_descendant_id' => $possibleDescendantId,
        ]) !== 0;
    }

    /** @return list<array<string, mixed>> */
    private function rows(PDOStatement $statement): array
    {
        $items = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $items[] = [
                'id' => (string) $row['id'],
                'parent_id' => $row['parent_id'] === null ? null : (string) $row['parent_id'],
                'code' => $row['code'],
                'name' => $row['name'],
                'sort_order' => (int) $row['sort_order'],
                'status' => $row['status'],
                'revision' => (string) $row['revision'],
            ];
        }

        return $items;
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
        int $departmentId,
        string $requestId,
    ): void {
        $this->execute(<<<'SQL'
INSERT INTO pa_tenant_audit_event (
    tenant_id, event_type, action, outcome, actor_tenant_id, actor_tenant_member_id,
    actor_account_id, actor_type, target_resource_type, target_resource_id,
    target_count, request_id, occurred_at
) VALUES (
    :tenant_id, :event_type, :action, 'success', :actor_tenant_id, :actor_member_id,
    :actor_account_id, 'member', 'department', :target_id,
    1, :request_id, :occurred_at
)
SQL, [
            'tenant_id' => $tenantId,
            'actor_tenant_id' => $tenantId,
            'event_type' => $eventType,
            'action' => $action,
            'actor_member_id' => $actorMemberId,
            'actor_account_id' => $actorAccountId,
            'target_id' => (string) $departmentId,
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
                throw AdminAccessException::conflict('DEPARTMENT_CONFLICT', 'Department code or relation conflicts.');
            }

            throw $exception;
        }
    }
}
