<?php

declare(strict_types=1);

namespace PeanutAdmin\Testing\Authorization;

use PDO;
use RuntimeException;

final readonly class PdoAuthorizationFixtureSeeder
{
    public function __construct(private PDO $pdo) {}

    public function roleForMember(int $tenantId, int $memberId): int
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT role.id
FROM pa_member_role member_role
JOIN pa_role role
  ON role.tenant_id = member_role.tenant_id
 AND role.id = member_role.role_id
 AND role.status = 'active'
WHERE member_role.tenant_id = :tenant_id
  AND member_role.tenant_member_id = :member_id
ORDER BY role.id
LIMIT 1
SQL);
        $statement->execute(['tenant_id' => $tenantId, 'member_id' => $memberId]);
        $roleId = $statement->fetchColumn();
        if ($roleId === false) {
            throw new RuntimeException('The fixture member has no active role.');
        }

        return (int) $roleId;
    }

    /** @param list<string> $permissionKeys */
    public function grantPermissions(int $tenantId, int $roleId, array $permissionKeys): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT IGNORE INTO pa_role_permission (tenant_id, role_id, permission_id, granted_at)
SELECT :tenant_id, :role_id, permission.id, UTC_TIMESTAMP(3)
FROM pa_permission permission
WHERE permission.`key` = :permission_key AND permission.status = 'active'
SQL);
        foreach (array_values(array_unique($permissionKeys)) as $permissionKey) {
            $statement->execute([
                'tenant_id' => $tenantId,
                'role_id' => $roleId,
                'permission_key' => $permissionKey,
            ]);
            if ($statement->rowCount() === 0 && !$this->roleHasPermission($tenantId, $roleId, $permissionKey)) {
                throw new RuntimeException("Fixture permission does not exist: {$permissionKey}");
            }
        }
        $this->bumpAuthorizationRevision($tenantId, $roleId);
    }

    /** @param non-empty-list<string> $targetIds */
    public function targetSet(int $tenantId, int $memberId, string $resourceKey, array $targetIds): int
    {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO pa_data_permission_target_set (
    tenant_id, name, target_mode, target_resource_key,
    created_by_member_id, updated_by_member_id, created_at, updated_at
) VALUES (
    :tenant_id, :name, 'resource', :resource_key,
    :created_by_member_id, :updated_by_member_id, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3)
)
SQL);
        $statement->execute([
            'tenant_id' => $tenantId,
            'name' => 'Fixture ' . $resourceKey . ' ' . bin2hex(random_bytes(4)),
            'resource_key' => $resourceKey,
            'created_by_member_id' => $memberId,
            'updated_by_member_id' => $memberId,
        ]);
        $targetSetId = (int) $this->pdo->lastInsertId();
        $target = $this->pdo->prepare(<<<'SQL'
INSERT INTO pa_data_permission_target (
    tenant_id, target_set_id, target_id, added_by_member_id, added_at
) VALUES (:tenant_id, :target_set_id, :target_id, :member_id, UTC_TIMESTAMP(3))
SQL);
        foreach (array_values(array_unique($targetIds, SORT_STRING)) as $targetId) {
            $target->execute([
                'tenant_id' => $tenantId,
                'target_set_id' => $targetSetId,
                'target_id' => $targetId,
                'member_id' => $memberId,
            ]);
        }

        return $targetSetId;
    }

    /**
     * @param non-empty-list<array<string, int>> $targetGroups target resource key => target set ID
     */
    public function allowTargetGroups(
        int $tenantId,
        int $roleId,
        int $memberId,
        string $resourceKey,
        string $operation,
        array $targetGroups,
    ): void {
        [$resourceId, $operationId] = $this->operation($resourceKey, $operation);
        $policyId = $this->policy($tenantId, $roleId, $memberId, $resourceId, $operationId);
        $conditionId = $this->conditionId('core.specified_objects');
        foreach ($targetGroups as $index => $targets) {
            $groupId = $this->group($tenantId, $policyId, $index);
            foreach ($targets as $targetSetId) {
                $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO pa_data_permission_condition (
    tenant_id, data_permission_group_id, condition_definition_id,
    target_set_id, created_at, updated_at
) VALUES (
    :tenant_id, :group_id, :condition_id,
    :target_set_id, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3)
)
SQL);
                $statement->execute([
                    'tenant_id' => $tenantId,
                    'group_id' => $groupId,
                    'condition_id' => $conditionId,
                    'target_set_id' => $targetSetId,
                ]);
            }
        }
        $this->bumpAuthorizationRevision($tenantId, $roleId);
    }

    public function allowTenantAll(
        int $tenantId,
        int $roleId,
        int $memberId,
        string $resourceKey,
        string $operation,
    ): void {
        [$resourceId, $operationId] = $this->operation($resourceKey, $operation);
        $policyId = $this->policy($tenantId, $roleId, $memberId, $resourceId, $operationId);
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO pa_data_permission_condition (
    tenant_id, data_permission_group_id, condition_definition_id,
    target_set_id, created_at, updated_at
) VALUES (
    :tenant_id, :group_id, :condition_id,
    NULL, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3)
)
SQL);
        $statement->execute([
            'tenant_id' => $tenantId,
            'group_id' => $this->group($tenantId, $policyId, 0),
            'condition_id' => $this->conditionId('core.tenant_all'),
        ]);
        $this->bumpAuthorizationRevision($tenantId, $roleId);
    }

    /** @return array{int, int} */
    private function operation(string $resourceKey, string $operation): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT resource.id AS resource_id, operation.id AS operation_id
FROM pa_protected_resource resource
JOIN pa_resource_operation operation ON operation.protected_resource_id = resource.id
WHERE resource.`key` = :resource_key AND resource.status = 'active'
  AND operation.operation = :operation AND operation.status = 'active'
SQL);
        $statement->execute(['resource_key' => $resourceKey, 'operation' => $operation]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException("Fixture operation does not exist: {$resourceKey}:{$operation}");
        }

        return [(int) $row['resource_id'], (int) $row['operation_id']];
    }

    private function policy(
        int $tenantId,
        int $roleId,
        int $memberId,
        int $resourceId,
        int $operationId,
    ): int {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO pa_data_permission_policy (
    tenant_id, role_id, protected_resource_id, resource_operation_id,
    created_by_member_id, updated_by_member_id, created_at, updated_at
) VALUES (
    :tenant_id, :role_id, :resource_id, :operation_id,
    :created_by_member_id, :updated_by_member_id, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3)
)
SQL);
        $statement->execute([
            'tenant_id' => $tenantId,
            'role_id' => $roleId,
            'resource_id' => $resourceId,
            'operation_id' => $operationId,
            'created_by_member_id' => $memberId,
            'updated_by_member_id' => $memberId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function group(int $tenantId, int $policyId, int $index): int
    {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO pa_data_permission_group (
    tenant_id, data_permission_policy_id, name, sort_order, created_at, updated_at
) VALUES (
    :tenant_id, :policy_id, :name, :sort_order, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3)
)
SQL);
        $statement->execute([
            'tenant_id' => $tenantId,
            'policy_id' => $policyId,
            'name' => 'Fixture group ' . ($index + 1),
            'sort_order' => $index,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function conditionId(string $key): int
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM pa_data_condition_definition WHERE `key` = :condition_key AND status = \'active\'',
        );
        $statement->execute(['condition_key' => $key]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            throw new RuntimeException("Fixture condition does not exist: {$key}");
        }

        return (int) $id;
    }

    private function roleHasPermission(int $tenantId, int $roleId, string $permissionKey): bool
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT relation.permission_id
FROM pa_role_permission relation
JOIN pa_permission permission ON permission.id = relation.permission_id
WHERE relation.tenant_id = :tenant_id AND relation.role_id = :role_id
  AND permission.`key` = :permission_key
SQL);
        $statement->execute([
            'tenant_id' => $tenantId,
            'role_id' => $roleId,
            'permission_key' => $permissionKey,
        ]);

        return $statement->fetchColumn() !== false;
    }

    private function bumpAuthorizationRevision(int $tenantId, int $roleId): void
    {
        $role = $this->pdo->prepare(<<<'SQL'
UPDATE pa_role SET authorization_revision = authorization_revision + 1, updated_at = UTC_TIMESTAMP(3)
WHERE tenant_id = :tenant_id AND id = :role_id
SQL);
        $role->execute(['tenant_id' => $tenantId, 'role_id' => $roleId]);
        $tenant = $this->pdo->prepare(<<<'SQL'
UPDATE pa_tenant SET authorization_revision = authorization_revision + 1, updated_at = UTC_TIMESTAMP(3)
WHERE id = :tenant_id
SQL);
        $tenant->execute(['tenant_id' => $tenantId]);
    }
}
