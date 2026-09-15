<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tenancy\Application;

use JsonException;
use PeanutAdmin\Kernel\Audit\GovernanceAuditFilter;
use PeanutAdmin\Kernel\Audit\GovernanceAuditMetadata;
use PeanutAdmin\Kernel\Audit\Model\TenantAuditEventRecord;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Kernel\Module\Model\ModuleInstallation;
use PeanutAdmin\Kernel\Module\Model\TenantModule;
use RuntimeException;
use think\db\Query;
use think\facade\Db;

final readonly class TenantWorkspaceQueryService
{
    /** @return array<string, mixed> */
    public function tenant(int $tenantId): array
    {
        $row = Db::name('tenant')->where('id', $tenantId)->field(
            'id,code,name,display_name,status,locale,timezone,security_revision,authorization_revision,revision,created_at,updated_at',
        )->find();
        if ($row === null) {
            throw AdminAccessException::notFound();
        }

        return $this->normalize($row);
    }

    /** @return list<array<string, mixed>> */
    public function permissions(int $tenantId): array
    {
        $modules = TenantModule::alias('tenant_module')
            ->join(
                'module_installation installation',
                "installation.module_key = tenant_module.module_key AND installation.status = 'active'",
            )
            ->where('tenant_module.tenant_id', $tenantId)
            ->where('tenant_module.status', 'enabled')
            ->where(function ($query): void {
                $query->whereNull('tenant_module.effective_at')
                    ->whereOr('tenant_module.effective_at', '<=', Db::raw('UTC_TIMESTAMP(3)'));
            })
            ->where(function ($query): void {
                $query->whereNull('tenant_module.expires_at')
                    ->whereOr('tenant_module.expires_at', '>', Db::raw('UTC_TIMESTAMP(3)'));
            })
            ->column('tenant_module.module_key');
        $rows = Db::name('permission')
            ->where('status', 'active')
            ->whereNotLike('key', 'platform.%')
            ->whereIn('module_key', array_values(array_unique(['core', ...$modules])))
            ->field('id,key,module_key,type,name,description,risk_level')
            ->order('module_key')
            ->order('key')
            ->select()
            ->toArray();

        return array_values(array_map($this->normalize(...), $rows));
    }

    /** @return list<array<string, mixed>> */
    public function modules(int $tenantId): array
    {
        $rows = ModuleInstallation::alias('installation')
            ->leftJoin(
                'tenant_module tenant_module',
                'tenant_module.module_key = installation.module_key AND tenant_module.tenant_id = ' . $tenantId,
            )
            ->field([
                'installation.module_key', 'installation.module_key' => 'name',
                'installation.installed_version' => 'version', 'installation.status' => 'deployment_status',
                'status' => Db::raw("COALESCE(tenant_module.status, 'disabled')"),
                'tenant_module.source', 'tenant_module.config_json', 'tenant_module.config_revision' => 'revision',
                'tenant_module.effective_at', 'tenant_module.expires_at',
                'tenant_module.enabled_at', 'tenant_module.disabled_at',
            ])
            ->order('installation.module_key')
            ->select()
            ->toArray();
        foreach ($rows as &$row) {
            $row = $this->normalize($row);
            $row['config'] = $this->decodeJson($row['config_json'] ?? null);
            unset($row['config_json']);
        }
        unset($row);

        return array_values($rows);
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function auditEvents(int $tenantId, PageRequest $page, ?GovernanceAuditFilter $filter = null): array
    {
        $query = TenantAuditEventRecord::where('tenant_id', $tenantId);
        $this->applyAuditFilter($query, $filter ?? new GovernanceAuditFilter());
        $total = (int) (clone $query)->count();
        $rows = $query->field([
            'id', 'event_type', 'action', 'outcome', 'reason_code', 'actor_type',
            'actor_tenant_member_id', 'actor_platform_operator_id',
            'actor_id' => Db::raw('COALESCE(actor_tenant_member_id, actor_platform_operator_id)'),
            'actor_type' => 'actor_label', 'target_resource_type', 'target_resource_id',
            'boundary_target_type', 'boundary_target_id', 'target_count', 'target_set_digest',
            'request_id', 'operation_id', 'occurred_at' => 'created_at',
        ])->order('occurred_at', 'desc')->order('id', 'desc')
            ->limit($page->offset(), $page->pageSize)->select()->toArray();

        return ['items' => array_values(array_map($this->normalize(...), $rows)), 'total' => $total];
    }

    /** @return array<string, mixed> */
    public function auditEvent(int $tenantId, string $eventId): array
    {
        if (preg_match('/^[1-9][0-9]*$/D', $eventId) !== 1) {
            throw AdminAccessException::notFound();
        }
        $row = TenantAuditEventRecord::where('tenant_id', $tenantId)
            ->where('id', $eventId)
            ->field([
                'id', 'event_type', 'action', 'outcome', 'reason_code', 'actor_type',
                'actor_tenant_member_id', 'actor_platform_operator_id',
                'actor_id' => Db::raw('COALESCE(actor_tenant_member_id, actor_platform_operator_id)'),
                'actor_type' => 'actor_label', 'target_resource_type', 'target_resource_id',
                'boundary_target_type', 'boundary_target_id', 'target_count', 'target_set_digest',
                'request_id', 'operation_id', 'metadata_json', 'occurred_at' => 'created_at',
            ])->find();
        if ($row === null) {
            throw AdminAccessException::notFound();
        }
        $row['metadata'] = $this->auditMetadata($row['metadata_json'] ?? null);
        unset($row['metadata_json']);

        return $this->normalize($row);
    }

    private function applyAuditFilter(Query $query, GovernanceAuditFilter $filter): void
    {
        foreach ([
            'event_type' => $filter->eventType,
            'action' => $filter->action,
            'outcome' => $filter->outcome?->value,
            'request_id' => $filter->requestId,
            'target_resource_type' => $filter->targetType,
            'target_resource_id' => $filter->targetId,
        ] as $column => $value) {
            if ($value !== null) {
                $query->where($column, $value);
            }
        }
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

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        foreach ($row as $key => $value) {
            if ($value !== null && ($key === 'id' || str_ends_with($key, '_id')
                || str_ends_with($key, '_revision') || $key === 'revision')) {
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
}
