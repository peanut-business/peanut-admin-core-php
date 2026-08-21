<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Catalog;

use PDO;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;

final readonly class PdoResourceOperationCatalog implements ResourceOperationCatalog
{
    public function __construct(private PDO $pdo) {}

    public function find(string $resourceKey, string $operation): ?ResourceOperation
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT ro.id, ro.protected_resource_id, ro.operation, ro.access_mode,
       ro.target_cardinality, ro.permission_match,
       pr.`key` AS resource_key, pr.module_key, pr.provider_key, pr.ownership
FROM pa_resource_operation ro
JOIN pa_protected_resource pr ON pr.id = ro.protected_resource_id AND pr.status = 'active'
WHERE pr.`key` = :resource_key AND ro.operation = :operation AND ro.status = 'active'
SQL);
        $statement->execute(['resource_key' => $resourceKey, 'operation' => $operation]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
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
        $count = $this->pdo->prepare(<<<SQL
SELECT COUNT(*)
FROM pa_resource_operation ro
JOIN pa_protected_resource pr
  ON pr.id = ro.protected_resource_id AND pr.status = 'active'
WHERE ro.status = 'active' AND ({$availability})
SQL);
        $count->execute(['tenant_id' => $tenantId]);
        $total = (int) $count->fetchColumn();
        if ($total === 0 || $page->page > intdiv($total - 1, $page->pageSize) + 1) {
            return ['items' => [], 'total' => $total];
        }

        $statement = $this->pdo->prepare(<<<SQL
SELECT ro.id, ro.protected_resource_id, ro.operation, ro.access_mode,
       ro.target_cardinality, ro.permission_match,
       pr.`key` AS resource_key, pr.module_key, pr.provider_key, pr.ownership
FROM pa_resource_operation ro
JOIN pa_protected_resource pr
  ON pr.id = ro.protected_resource_id AND pr.status = 'active'
WHERE ro.status = 'active' AND ({$availability})
ORDER BY pr.`key`, ro.operation, ro.id
LIMIT :limit OFFSET :offset
SQL);
        $statement->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $page->pageSize, PDO::PARAM_INT);
        $statement->bindValue(':offset', $page->offset(), PDO::PARAM_INT);
        $statement->execute();
        $items = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
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
        $statement = $this->pdo->prepare(<<<'SQL'
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
SQL);
        $statement->execute(['tenant_id' => $tenantId, 'module_key' => $moduleKey]);

        return $statement->fetchColumn() !== false;
    }

    public function registryRevision(): string
    {
        $statement = $this->pdo->query(<<<'SQL'
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

        return hash('sha256', implode('|', $statement === false ? [] : $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return list<string> */
    private function permissionKeys(int $operationId): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT p.`key`
FROM pa_resource_operation_permission relation
JOIN pa_permission p ON p.id = relation.permission_id AND p.status = 'active'
WHERE relation.resource_operation_id = :operation_id
ORDER BY relation.sort_order, p.`key`
SQL);
        $statement->execute(['operation_id' => $operationId]);

        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return list<OperationTargetType> */
    private function targetTypes(int $operationId): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
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
SQL);
        $statement->execute(['operation_id' => $operationId]);
        $targetTypes = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
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
}
