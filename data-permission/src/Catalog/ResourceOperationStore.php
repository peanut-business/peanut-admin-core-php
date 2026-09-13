<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Catalog;

use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use think\db\PDOConnection;

final readonly class ResourceOperationStore implements ResourceOperationCatalog
{
    public function __construct(private PDOConnection $connection) {}

    public function find(string $resourceKey, string $operation): ?ResourceOperation
    {
        $row = $this->one(<<<'SQL'
SELECT ro.id, ro.protected_resource_id, ro.operation, ro.access_mode,
       ro.target_cardinality, ro.permission_match,
       pr.`key` AS resource_key, pr.module_key, pr.provider_key, pr.ownership
FROM pa_resource_operation ro
JOIN pa_protected_resource pr ON pr.id = ro.protected_resource_id AND pr.status = 'active'
WHERE pr.`key` = :resource_key AND ro.operation = :operation AND ro.status = 'active'
SQL, ['resource_key' => $resourceKey, 'operation' => $operation]);
        if ($row === null) {
            return null;
        }
        $operationId = (int) $row['id'];

        return new ResourceOperation(
            $operationId,
            (int) $row['protected_resource_id'],
            (string) $row['resource_key'],
            (string) $row['module_key'],
            (string) $row['provider_key'],
            (string) $row['ownership'],
            (string) $row['operation'],
            (string) $row['access_mode'],
            (string) $row['target_cardinality'],
            (string) $row['permission_match'],
            $this->permissionKeys($operationId),
            $this->targetTypes($operationId),
        );
    }

    public function availableOperations(int $tenantId, PageRequest $page): array
    {
        $availability = <<<'SQL'
pr.module_key = 'core'
OR EXISTS (
    SELECT 1
    FROM pa_tenant_module tenant_module
    JOIN pa_module_installation installation
      ON installation.module_key = tenant_module.module_key
     AND installation.status = 'active'
    WHERE tenant_module.tenant_id = :tenant_id
      AND tenant_module.module_key = pr.module_key
      AND tenant_module.status = 'enabled'
      AND (tenant_module.effective_at IS NULL OR tenant_module.effective_at <= CURRENT_TIMESTAMP(3))
      AND (tenant_module.expires_at IS NULL OR tenant_module.expires_at > CURRENT_TIMESTAMP(3))
)
SQL;
        $countRow = $this->one(<<<SQL
SELECT COUNT(*) AS aggregate
FROM pa_resource_operation ro
JOIN pa_protected_resource pr
  ON pr.id = ro.protected_resource_id AND pr.status = 'active'
WHERE ro.status = 'active' AND ({$availability})
SQL, ['tenant_id' => $tenantId]);
        $total = (int) ($countRow['aggregate'] ?? 0);
        if ($total === 0 || $page->page > intdiv($total - 1, $page->pageSize) + 1) {
            return ['items' => [], 'total' => $total];
        }

        $rows = $this->connection->query(sprintf(<<<SQL
SELECT ro.id, ro.protected_resource_id, ro.operation, ro.access_mode,
       ro.target_cardinality, ro.permission_match,
       pr.`key` AS resource_key, pr.module_key, pr.provider_key, pr.ownership
FROM pa_resource_operation ro
JOIN pa_protected_resource pr
  ON pr.id = ro.protected_resource_id AND pr.status = 'active'
WHERE ro.status = 'active' AND ({$availability})
ORDER BY pr.`key`, ro.operation, ro.id LIMIT %d OFFSET %d
SQL, $page->pageSize, $page->offset()), ['tenant_id' => $tenantId]);
        $items = [];
        foreach ($rows as $row) {
            $operationId = (int) $row['id'];
            $items[] = new ResourceOperation(
                $operationId,
                (int) $row['protected_resource_id'],
                (string) $row['resource_key'],
                (string) $row['module_key'],
                (string) $row['provider_key'],
                (string) $row['ownership'],
                (string) $row['operation'],
                (string) $row['access_mode'],
                (string) $row['target_cardinality'],
                (string) $row['permission_match'],
                $this->permissionKeys($operationId),
                $this->targetTypes($operationId),
            );
        }

        return ['items' => $items, 'total' => $total];
    }

    public function moduleAvailable(int $tenantId, string $moduleKey): bool
    {
        if ($moduleKey === 'core') {
            return true;
        }
        $row = $this->one(<<<'SQL'
SELECT tenant_module.id
FROM pa_tenant_module tenant_module
JOIN pa_module_installation installation
  ON installation.module_key = tenant_module.module_key
 AND installation.status = 'active'
WHERE tenant_module.tenant_id = :tenant_id
  AND tenant_module.module_key = :module_key
  AND tenant_module.status = 'enabled'
  AND (tenant_module.effective_at IS NULL OR tenant_module.effective_at <= CURRENT_TIMESTAMP(3))
  AND (tenant_module.expires_at IS NULL OR tenant_module.expires_at > CURRENT_TIMESTAMP(3))
LIMIT 1
SQL, ['tenant_id' => $tenantId, 'module_key' => $moduleKey]);

        return $row !== null;
    }

    public function registryRevision(): string
    {
        $rows = $this->connection->query(<<<'SQL'
SELECT digest FROM (
    SELECT CONCAT('resource:', id, ':', status, ':', manifest_digest) AS digest FROM pa_protected_resource
    UNION ALL
    SELECT CONCAT('operation:', id, ':', status, ':', manifest_digest) FROM pa_resource_operation
    UNION ALL
    SELECT CONCAT('module-installation:', module_key, ':', status, ':', revision, ':', manifest_digest)
    FROM pa_module_installation
    UNION ALL
    SELECT CONCAT('target:', id, ':', status, ':', manifest_digest) FROM pa_target_type
    UNION ALL
    SELECT CONCAT('condition:', id, ':', status, ':', manifest_digest) FROM pa_data_condition_definition
) registry ORDER BY digest
SQL);

        return hash('sha256', implode('|', array_map(
            static fn(array $row): string => (string) $row['digest'],
            $rows,
        )));
    }

    /** @return list<string> */
    private function permissionKeys(int $operationId): array
    {
        $rows = $this->connection->query(<<<'SQL'
SELECT p.`key`
FROM pa_resource_operation_permission relation
JOIN pa_permission p ON p.id = relation.permission_id AND p.status = 'active'
WHERE relation.resource_operation_id = :operation_id
ORDER BY relation.sort_order, p.`key`
SQL, ['operation_id' => $operationId]);

        return array_values(array_map(static fn(array $row): string => (string) $row['key'], $rows));
    }

    /** @return list<OperationTargetType> */
    private function targetTypes(int $operationId): array
    {
        $rows = $this->connection->query(<<<'SQL'
SELECT relation.target_role, relation.input_mode,
       target.`key`, target.resolver_key, target.catalog_provider_key,
       selection_permission.`key` AS policy_selection_permission_key
FROM pa_resource_operation_target_type relation
JOIN pa_target_type target ON target.id = relation.target_type_id AND target.status = 'active'
LEFT JOIN pa_permission selection_permission
  ON selection_permission.id = relation.policy_selection_permission_id
 AND selection_permission.status = 'active'
WHERE relation.resource_operation_id = :operation_id AND relation.status = 'active'
ORDER BY relation.target_role, target.`key`
SQL, ['operation_id' => $operationId]);
        $targetTypes = [];
        foreach ($rows as $row) {
            $targetTypes[] = new OperationTargetType(
                (string) $row['target_role'],
                (string) $row['key'],
                (string) $row['resolver_key'],
                (string) $row['catalog_provider_key'],
                (string) $row['input_mode'],
                is_string($row['policy_selection_permission_key'])
                    ? $row['policy_selection_permission_key']
                    : null,
            );
        }

        return $targetTypes;
    }

    /** @param array<string, int|string> $parameters
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $parameters = []): ?array
    {
        $row = $this->connection->query($sql, $parameters)[0] ?? null;

        return is_array($row) ? $row : null;
    }
}
