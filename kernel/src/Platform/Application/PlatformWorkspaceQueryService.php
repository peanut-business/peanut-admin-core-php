<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Platform\Application;

use JsonException;
use PDO;
use PDOStatement;
use PeanutAdmin\Kernel\Audit\GovernanceAuditFilter;
use PeanutAdmin\Kernel\Audit\GovernanceAuditMetadata;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use RuntimeException;

final readonly class PlatformWorkspaceQueryService
{
    public function __construct(private PDO $pdo) {}

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function tenants(PageRequest $page): array
    {
        return $this->page(
            'SELECT COUNT(*) FROM pa_tenant',
            <<<'SQL'
SELECT id, code, name, display_name, status, locale, timezone,
       security_revision, authorization_revision, revision,
       activated_at, suspended_at, closed_at, created_at, updated_at
FROM pa_tenant
ORDER BY id
LIMIT :limit OFFSET :offset
SQL,
            $page,
        );
    }

    /** @return array<string, mixed> */
    public function tenant(int $tenantId): array
    {
        return $this->one(
            <<<'SQL'
SELECT id, code, name, display_name, status, locale, timezone,
       security_revision, authorization_revision, revision,
       activated_at, suspended_at, closed_at, created_at, updated_at
FROM pa_tenant
WHERE id = :tenant_id
SQL,
            ['tenant_id' => $tenantId],
        );
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function operators(PageRequest $page): array
    {
        return $this->page(
            'SELECT COUNT(*) FROM pa_platform_operator',
            <<<'SQL'
SELECT po.id, po.account_id, COALESCE(po.display_name, a.display_name) AS display_name,
       MAX(CASE WHEN c.identifier_type = 'email' AND c.status = 'active'
           THEN c.identifier_normalized END) AS email,
       po.status, po.security_revision, po.suspended_at, po.closed_at,
       po.created_at, po.updated_at,
       GROUP_CONCAT(DISTINCT pr.`key` ORDER BY pr.`key` SEPARATOR ',') AS role_keys_csv
FROM pa_platform_operator po
JOIN pa_account a ON a.id = po.account_id
LEFT JOIN pa_credential c ON c.account_id = a.id
LEFT JOIN pa_platform_operator_role por ON por.platform_operator_id = po.id
LEFT JOIN pa_platform_role pr ON pr.id = por.platform_role_id AND pr.status = 'active'
GROUP BY po.id, po.account_id, po.display_name, a.display_name, po.status,
         po.security_revision, po.suspended_at, po.closed_at, po.created_at, po.updated_at
ORDER BY po.id
LIMIT :limit OFFSET :offset
SQL,
            $page,
            ['role_keys_csv' => 'role_keys'],
        );
    }

    /** @return array<string, mixed> */
    public function operator(int $operatorId): array
    {
        return $this->one(
            <<<'SQL'
SELECT po.id, po.account_id, COALESCE(po.display_name, a.display_name) AS display_name,
       MAX(CASE WHEN c.identifier_type = 'email' AND c.status = 'active'
           THEN c.identifier_normalized END) AS email,
       po.status, po.security_revision, po.suspended_at, po.closed_at,
       po.created_at, po.updated_at,
       GROUP_CONCAT(DISTINCT pr.`key` ORDER BY pr.`key` SEPARATOR ',') AS role_keys_csv
FROM pa_platform_operator po
JOIN pa_account a ON a.id = po.account_id
LEFT JOIN pa_credential c ON c.account_id = a.id
LEFT JOIN pa_platform_operator_role por ON por.platform_operator_id = po.id
LEFT JOIN pa_platform_role pr ON pr.id = por.platform_role_id AND pr.status = 'active'
WHERE po.id = :operator_id
GROUP BY po.id, po.account_id, po.display_name, a.display_name, po.status,
         po.security_revision, po.suspended_at, po.closed_at, po.created_at, po.updated_at
SQL,
            ['operator_id' => $operatorId],
            ['role_keys_csv' => 'role_keys'],
        );
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function roles(PageRequest $page): array
    {
        return $this->page(
            'SELECT COUNT(*) FROM pa_platform_role',
            <<<'SQL'
SELECT pr.id, pr.`key`, pr.name, pr.description, pr.is_builtin, pr.status,
       pr.revision, pr.archived_at, pr.created_at, pr.updated_at,
       COUNT(DISTINCT CASE WHEN p.status = 'active' AND p.`key` LIKE 'platform.%'
           THEN p.id END) AS permission_count
FROM pa_platform_role pr
LEFT JOIN pa_platform_role_permission prp ON prp.platform_role_id = pr.id
LEFT JOIN pa_permission p ON p.id = prp.permission_id
GROUP BY pr.id, pr.`key`, pr.name, pr.description, pr.is_builtin, pr.status,
         pr.revision, pr.archived_at, pr.created_at, pr.updated_at
ORDER BY pr.id
LIMIT :limit OFFSET :offset
SQL,
            $page,
        );
    }

    /** @return array<string, mixed> */
    public function role(int $roleId): array
    {
        return $this->one(
            <<<'SQL'
SELECT pr.id, pr.`key`, pr.name, pr.description, pr.is_builtin, pr.status,
       pr.revision, pr.archived_at, pr.created_at, pr.updated_at,
       COUNT(DISTINCT CASE WHEN p.status = 'active' AND p.`key` LIKE 'platform.%'
           THEN p.id END) AS permission_count,
       GROUP_CONCAT(DISTINCT CASE WHEN p.status = 'active' AND p.`key` LIKE 'platform.%'
           THEN p.`key` END ORDER BY p.`key` SEPARATOR ',') AS permission_keys_csv
FROM pa_platform_role pr
LEFT JOIN pa_platform_role_permission prp ON prp.platform_role_id = pr.id
LEFT JOIN pa_permission p ON p.id = prp.permission_id
WHERE pr.id = :role_id
GROUP BY pr.id, pr.`key`, pr.name, pr.description, pr.is_builtin, pr.status,
         pr.revision, pr.archived_at, pr.created_at, pr.updated_at
SQL,
            ['role_id' => $roleId],
            ['permission_keys_csv' => 'permission_keys'],
        );
    }

    /** @return list<array<string, mixed>> */
    public function permissions(): array
    {
        $statement = $this->statement(<<<'SQL'
SELECT id, `key`, module_key, type, name, description, risk_level
FROM pa_permission
WHERE status = 'active' AND `key` LIKE 'platform.%'
ORDER BY `key`
SQL);
        $statement->execute();

        return $this->rows($statement);
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function auditEvents(PageRequest $page, ?GovernanceAuditFilter $filter = null): array
    {
        [$where, $parameters] = $this->auditWhere($filter ?? new GovernanceAuditFilter());
        return $this->page(
            'SELECT COUNT(*) FROM pa_platform_audit_event pae WHERE ' . $where,
            <<<SQL
SELECT pae.id, pae.event_type, pae.action, pae.outcome, pae.reason_code,
       pae.operator_id, pae.account_id,
       COALESCE(po.display_name, a.display_name, 'platform_system') AS operator_label,
       pae.target_type, pae.target_id,
       CASE WHEN pae.target_type = 'tenant' THEN pae.target_id ELSE NULL END AS target_tenant_id,
       pae.request_id, pae.operation_id, pae.occurred_at AS created_at
FROM pa_platform_audit_event pae
LEFT JOIN pa_platform_operator po ON po.id = pae.operator_id
LEFT JOIN pa_account a ON a.id = pae.account_id
WHERE {$where}
ORDER BY pae.occurred_at DESC, pae.id DESC
LIMIT :limit OFFSET :offset
SQL,
            $page,
            [],
            $parameters,
        );
    }

    /** @return array<string, mixed> */
    public function auditEvent(string $eventId): array
    {
        if (preg_match('/^[1-9][0-9]*$/D', $eventId) !== 1) {
            throw AdminAccessException::notFound();
        }
        $row = $this->one(<<<'SQL'
SELECT pae.id, pae.event_type, pae.action, pae.outcome, pae.reason_code,
       pae.operator_id, pae.account_id,
       COALESCE(po.display_name, a.display_name, 'platform_system') AS operator_label,
       pae.target_type, pae.target_id,
       CASE WHEN pae.target_type = 'tenant' THEN pae.target_id ELSE NULL END AS target_tenant_id,
       pae.request_id, pae.operation_id, pae.metadata_json, pae.occurred_at AS created_at
FROM pa_platform_audit_event pae
LEFT JOIN pa_platform_operator po ON po.id = pae.operator_id
LEFT JOIN pa_account a ON a.id = pae.account_id
WHERE pae.id = :event_id
SQL, ['event_id' => $eventId]);
        $row['metadata'] = $this->auditMetadata($row['metadata_json'] ?? null);
        unset($row['metadata_json']);

        return $row;
    }

    /**
     * @param array<string, string> $csvFields source field => result field
     * @param array<string, string> $parameters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    private function page(
        string $countSql,
        string $querySql,
        PageRequest $page,
        array $csvFields = [],
        array $parameters = [],
    ): array {
        $count = $this->statement($countSql);
        $count->execute($parameters);
        $statement = $this->statement($querySql);
        foreach ($parameters as $key => $value) {
            $statement->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $page->pageSize, PDO::PARAM_INT);
        $statement->bindValue(':offset', $page->offset(), PDO::PARAM_INT);
        $statement->execute();

        return [
            'items' => $this->rows($statement, $csvFields),
            'total' => (int) $count->fetchColumn(),
        ];
    }

    /** @return array{string, array<string, string>} */
    private function auditWhere(GovernanceAuditFilter $filter): array
    {
        $conditions = ['1 = 1'];
        $parameters = [];
        foreach ([
            'event_type' => $filter->eventType,
            'action' => $filter->action,
            'outcome' => $filter->outcome?->value,
            'request_id' => $filter->requestId,
            'target_type' => $filter->targetType,
            'target_id' => $filter->targetId,
        ] as $column => $value) {
            if ($value !== null) {
                $conditions[] = "pae.{$column} = :{$column}";
                $parameters[$column] = $value;
            }
        }

        return [implode(' AND ', $conditions), $parameters];
    }

    /** @return array<string, bool|int|string|null> */
    private function auditMetadata(mixed $value): array
    {
        try {
            $decoded = is_string($value) && $value !== ''
                ? json_decode($value, true, 64, JSON_THROW_ON_ERROR)
                : [];
        } catch (JsonException) {
            $decoded = [];
        }

        return (new GovernanceAuditMetadata([
            'revision', 'permission_count', 'role_id', 'module_key', 'resource_key', 'operation', 'status', 'reason',
        ]))->project(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param array<string, int|string> $parameters
     * @param array<string, string> $csvFields source field => result field
     * @return array<string, mixed>
     */
    private function one(string $sql, array $parameters, array $csvFields = []): array
    {
        $statement = $this->statement($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw AdminAccessException::notFound();
        }

        return $this->normalize($row, $csvFields);
    }

    /**
     * @param array<string, string> $csvFields source field => result field
     * @return list<array<string, mixed>>
     */
    private function rows(PDOStatement $statement, array $csvFields = []): array
    {
        $rows = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rows[] = $this->normalize($row, $csvFields);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $csvFields source field => result field
     * @return array<string, mixed>
     */
    private function normalize(array $row, array $csvFields = []): array
    {
        foreach ($csvFields as $source => $target) {
            $value = $row[$source] ?? null;
            $row[$target] = is_string($value) && $value !== '' ? explode(',', $value) : [];
            unset($row[$source]);
        }
        foreach ($row as $key => $value) {
            if ($value !== null && ($key === 'id' || str_ends_with($key, '_id') || str_ends_with($key, '_revision') || $key === 'revision')) {
                $row[$key] = (string) $value;
            }
        }
        if (isset($row['permission_count'])) {
            $row['permission_count'] = (int) $row['permission_count'];
        }
        if (isset($row['is_builtin'])) {
            $row['is_builtin'] = (bool) $row['is_builtin'];
        }

        return $row;
    }

    private function statement(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Could not prepare platform workspace query.');
        }

        return $statement;
    }
}
