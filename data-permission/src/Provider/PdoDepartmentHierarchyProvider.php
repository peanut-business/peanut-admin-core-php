<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Provider;

use PDO;
use RuntimeException;

final readonly class PdoDepartmentHierarchyProvider implements DepartmentHierarchyProvider
{
    public function __construct(private PDO $pdo) {}

    public function descendantsIncludingSelf(int $tenantId, int $departmentId): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
WITH RECURSIVE descendants AS (
    SELECT id, 1 AS depth
    FROM pa_department
    WHERE tenant_id = :tenant_id AND id = :department_id AND status = 'active'
    UNION ALL
    SELECT department.id, descendants.depth + 1
    FROM pa_department department
    JOIN descendants ON department.parent_id = descendants.id
    WHERE department.tenant_id = :recursive_tenant_id
      AND department.status = 'active'
      AND descendants.depth < 10
)
SELECT id FROM descendants ORDER BY id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Could not prepare the department hierarchy query.');
        }
        $statement->execute([
            'tenant_id' => $tenantId,
            'department_id' => $departmentId,
            'recursive_tenant_id' => $tenantId,
        ]);

        return array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }
}
