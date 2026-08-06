<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tenancy\Application;

use JsonException;
use PDO;
use PDOStatement;
use PeanutAdmin\Kernel\Audit\GovernanceAuditFilter;
use PeanutAdmin\Kernel\Audit\GovernanceAuditMetadata;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use RuntimeException;

final readonly class TenantWorkspaceQueryService
{
    public function __construct(private PDO $pdo) {}

    /** @return array<string, mixed> */
    public function tenant(int $tenantId): array
    {
        $statement = $this->statement(<<<'SQL'
SELECT id, code, name, display_name, status, locale, timezone,
       security_revision, authorization_revision, revision, created_at, updated_at
FROM pa_tenant WHERE id = :tenant_id
SQL);
        $statement->execute(['tenant_id' => $tenantId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw AdminAccessException::notFound();
        }

        return $this->normalize($row);
    }

    /** @return list<array<string, mixed>> */
    public function permissions(int $tenantId): array
    {
        $statement = $this->statement(<<<'SQL'
SELECT p.id, p.`key`, p.module_key, p.type, p.name, p.description, p.risk_level
FROM pa_permission p
WHERE p.status = 'active' AND p.`key` NOT LIKE 'platform.%'
  AND (
    p.module_key = 'core'
    OR EXISTS (
      SELECT 1 FROM pa_tenant_module tm
      JOIN pa_module_installation mi ON mi.module_key = tm.module_key AND mi.status = 'active'
      WHERE tm.tenant_id = :tenant_id AND tm.module_key = p.module_key
        AND tm.status = 'enabled'
        AND (tm.effective_at IS NULL OR tm.effective_at <= CURRENT_TIMESTAMP(3))
        AND (tm.expires_at IS NULL OR tm.expires_at > CURRENT_TIMESTAMP(3))
    )
  )
ORDER BY p.module_key, p.`key`
SQL);
        $statement->execute(['tenant_id' => $tenantId]);

        return $this->rows($statement);
    }

    /** @return list<array<string, mixed>> */
    public function modules(int $tenantId): array
    {
        $statement = $this->statement(<<<'SQL'
SELECT mi.module_key, mi.module_key AS name, mi.installed_version AS version,
       mi.status AS deployment_status, COALESCE(tm.status, 'disabled') AS status,
       tm.source, tm.config_json, tm.config_revision AS revision,
       tm.effective_at, tm.expires_at, tm.enabled_at, tm.disabled_at
FROM pa_module_installation mi
LEFT JOIN pa_tenant_module tm
  ON tm.module_key = mi.module_key AND tm.tenant_id = :tenant_id
ORDER BY mi.module_key
SQL);
        $statement->execute(['tenant_id' => $tenantId]);
        $rows = $this->rows($statement);
        foreach ($rows as &$row) {
            $row['config'] = $this->decodeJson($row['config_json'] ?? null);
            unset($row['config_json']);
        }
        unset($row);

        return $rows;
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function auditEvents(int $tenantId, PageRequest $page, ?GovernanceAuditFilter $filter = null): array
    {
        [$where, $parameters] = $this->auditWhere($tenantId, $filter ?? new GovernanceAuditFilter());
        $count = $this->statement('SELECT COUNT(*) FROM pa_tenant_audit_event WHERE ' . $where);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $statement = $this->statement(<<<SQL
SELECT id, event_type, action, outcome, reason_code, actor_type,
       actor_tenant_member_id, actor_platform_operator_id,
       COALESCE(actor_tenant_member_id, actor_platform_operator_id) AS actor_id,
       actor_type AS actor_label, target_resource_type, target_resource_id,
       boundary_target_type, boundary_target_id, target_count, target_set_digest,
       request_id, operation_id, occurred_at AS created_at
FROM pa_tenant_audit_event
WHERE {$where}
ORDER BY occurred_at DESC, id DESC
LIMIT :limit OFFSET :offset
SQL);
        foreach ($parameters as $key => $value) {
            $statement->bindValue(':' . $key, $value, $key === 'tenant_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $page->pageSize, PDO::PARAM_INT);
        $statement->bindValue(':offset', $page->offset(), PDO::PARAM_INT);
        $statement->execute();

        return ['items' => $this->rows($statement), 'total' => $total];
    }

    /** @return array<string, mixed> */
    public function auditEvent(int $tenantId, string $eventId): array
    {
        if (preg_match('/^[1-9][0-9]*$/D', $eventId) !== 1) {
            throw AdminAccessException::notFound();
        }
        $statement = $this->statement(<<<'SQL'
SELECT id, event_type, action, outcome, reason_code, actor_type,
       actor_tenant_member_id, actor_platform_operator_id,
       COALESCE(actor_tenant_member_id, actor_platform_operator_id) AS actor_id,
       actor_type AS actor_label, target_resource_type, target_resource_id,
       boundary_target_type, boundary_target_id, target_count, target_set_digest,
       request_id, operation_id, metadata_json, occurred_at AS created_at
FROM pa_tenant_audit_event
WHERE tenant_id = :tenant_id AND id = :event_id
SQL);
        $statement->execute(['tenant_id' => $tenantId, 'event_id' => $eventId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw AdminAccessException::notFound();
        }
        $row['metadata'] = $this->auditMetadata($row['metadata_json'] ?? null);
        unset($row['metadata_json']);

        return $this->normalize($row);
    }

    /** @return array{string, array<string, int|string>} */
    private function auditWhere(int $tenantId, GovernanceAuditFilter $filter): array
    {
        $conditions = ['tenant_id = :tenant_id'];
        $parameters = ['tenant_id' => $tenantId];
        foreach ([
            'event_type' => $filter->eventType,
            'action' => $filter->action,
            'outcome' => $filter->outcome?->value,
            'request_id' => $filter->requestId,
            'target_resource_type' => $filter->targetType,
            'target_resource_id' => $filter->targetId,
        ] as $column => $value) {
            if ($value !== null) {
                $conditions[] = "{$column} = :{$column}";
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

    /** @return list<array<string, mixed>> */
    private function rows(PDOStatement $statement): array
    {
        $rows = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rows[] = $this->normalize($row);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        foreach ($row as $key => $value) {
            if ($value !== null && ($key === 'id' || str_ends_with($key, '_id') || str_ends_with($key, '_revision') || $key === 'revision')) {
                $row[$key] = (string) $value;
            }
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function decodeJson(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        try {
            $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored module configuration is invalid.', 0, $exception);
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function statement(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Could not prepare workspace query.');
        }

        return $statement;
    }
}
