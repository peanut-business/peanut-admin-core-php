<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Platform\Application;

use JsonException;
use PeanutAdmin\Kernel\Audit\GovernanceAuditFilter;
use PeanutAdmin\Kernel\Audit\GovernanceAuditMetadata;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Kernel\Audit\Model\PlatformAuditEventRecord;
use PeanutAdmin\Kernel\Persistence\Model\PlatformOperator;
use PeanutAdmin\Kernel\Persistence\Model\PlatformOperatorRole;
use PeanutAdmin\Kernel\Persistence\Model\PlatformRolePermission;
use think\db\Query;
use think\facade\Db;

final readonly class PlatformWorkspaceQueryService
{
    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function tenants(PageRequest $page): array
    {
        $query = Db::name('tenant');
        $total = (int) (clone $query)->count();
        $rows = $query->field(
            'id,code,name,display_name,status,locale,timezone,security_revision,authorization_revision,revision,activated_at,suspended_at,closed_at,created_at,updated_at',
        )->order('id')->limit($page->offset(), $page->pageSize)->select()->toArray();

        return ['items' => array_values(array_map($this->normalize(...), $rows)), 'total' => $total];
    }

    /** @return array<string, mixed> */
    public function tenant(int $tenantId): array
    {
        $row = Db::name('tenant')->where('id', $tenantId)->field(
            'id,code,name,display_name,status,locale,timezone,security_revision,authorization_revision,revision,activated_at,suspended_at,closed_at,created_at,updated_at',
        )->find();

        return $this->requireRow($row);
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function operators(PageRequest $page): array
    {
        $query = PlatformOperator::alias('operator')
            ->join('account account', 'account.id = operator.account_id');
        $total = (int) (clone $query)->count();
        $rows = $query->field([
            'operator.id', 'operator.account_id',
            'display_name' => Db::raw('COALESCE(operator.display_name, account.display_name)'),
            'operator.status', 'operator.security_revision', 'operator.suspended_at', 'operator.closed_at',
            'operator.created_at', 'operator.updated_at',
        ])->order('operator.id')->limit($page->offset(), $page->pageSize)->select()->toArray();

        return ['items' => $this->hydrateOperators(array_values($rows)), 'total' => $total];
    }

    /** @return array<string, mixed> */
    public function operator(int $operatorId): array
    {
        $row = PlatformOperator::alias('operator')
            ->join('account account', 'account.id = operator.account_id')
            ->where('operator.id', $operatorId)
            ->field([
                'operator.id', 'operator.account_id',
                'display_name' => Db::raw('COALESCE(operator.display_name, account.display_name)'),
                'operator.status', 'operator.security_revision', 'operator.suspended_at', 'operator.closed_at',
                'operator.created_at', 'operator.updated_at',
            ])->find();
        if ($row === null) {
            throw AdminAccessException::notFound();
        }

        return $this->hydrateOperators([$row])[0];
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function roles(PageRequest $page): array
    {
        $query = Db::name('platform_role');
        $total = (int) (clone $query)->count();
        $rows = $query->field(
            'id,key,name,description,is_builtin,status,revision,archived_at,created_at,updated_at',
        )->order('id')->limit($page->offset(), $page->pageSize)->select()->toArray();

        return ['items' => $this->hydrateRoles(array_values($rows), false), 'total' => $total];
    }

    /** @return array<string, mixed> */
    public function role(int $roleId): array
    {
        $row = Db::name('platform_role')->where('id', $roleId)->field(
            'id,key,name,description,is_builtin,status,revision,archived_at,created_at,updated_at',
        )->find();
        if ($row === null) {
            throw AdminAccessException::notFound();
        }

        return $this->hydrateRoles([$row], true)[0];
    }

    /** @return list<array<string, mixed>> */
    public function permissions(): array
    {
        $rows = Db::name('permission')
            ->where('status', 'active')
            ->whereLike('key', 'platform.%')
            ->field('id,key,module_key,type,name,description,risk_level')
            ->order('key')->select()->toArray();

        return array_values(array_map($this->normalize(...), $rows));
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function auditEvents(PageRequest $page, ?GovernanceAuditFilter $filter = null): array
    {
        $query = PlatformAuditEventRecord::alias('audit')
            ->leftJoin('platform_operator operator', 'operator.id = audit.operator_id')
            ->leftJoin('account account', 'account.id = audit.account_id');
        $this->applyAuditFilter($query, $filter ?? new GovernanceAuditFilter());
        $total = (int) (clone $query)->count();
        $rows = $query->field($this->auditFields(false))
            ->order('audit.occurred_at', 'desc')->order('audit.id', 'desc')
            ->limit($page->offset(), $page->pageSize)->select()->toArray();

        return ['items' => array_values(array_map($this->normalize(...), $rows)), 'total' => $total];
    }

    /** @return array<string, mixed> */
    public function auditEvent(string $eventId): array
    {
        if (preg_match('/^[1-9][0-9]*$/D', $eventId) !== 1) {
            throw AdminAccessException::notFound();
        }
        $row = PlatformAuditEventRecord::alias('audit')
            ->leftJoin('platform_operator operator', 'operator.id = audit.operator_id')
            ->leftJoin('account account', 'account.id = audit.account_id')
            ->where('audit.id', $eventId)
            ->field($this->auditFields(true))->find();
        if ($row === null) {
            throw AdminAccessException::notFound();
        }
        $row['metadata'] = $this->auditMetadata($row['metadata_json'] ?? null);
        unset($row['metadata_json']);

        return $this->normalize($row);
    }

    /** @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function hydrateOperators(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $accountIds = array_map('intval', array_column($rows, 'account_id'));
        $operatorIds = array_map('intval', array_column($rows, 'id'));
        $emails = [];
        foreach (Db::name('credential')->whereIn('account_id', $accountIds)
            ->where('identifier_type', 'email')->where('status', 'active')
            ->field('account_id,identifier_normalized')->order('id')->select()->toArray() as $credential) {
            $emails[(int) $credential['account_id']] ??= (string) $credential['identifier_normalized'];
        }
        $roleKeys = [];
        foreach (PlatformOperatorRole::alias('operator_role')
            ->join(
                'platform_role role',
                "role.id = operator_role.platform_role_id AND role.status = 'active'",
            )->whereIn('operator_role.platform_operator_id', $operatorIds)
            ->field(['operator_role.platform_operator_id', 'role.key'])->order('role.key')->select()->toArray() as $role) {
            $roleKeys[(int) $role['platform_operator_id']][] = (string) $role['key'];
        }
        foreach ($rows as &$row) {
            $operatorId = (int) $row['id'];
            $row['email'] = $emails[(int) $row['account_id']] ?? null;
            $row['role_keys'] = array_values(array_unique($roleKeys[$operatorId] ?? []));
            $row = $this->normalize($row);
        }
        unset($row);

        return $rows;
    }

    /** @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function hydrateRoles(array $rows, bool $includeKeys): array
    {
        if ($rows === []) {
            return [];
        }
        $roleIds = array_map('intval', array_column($rows, 'id'));
        $permissions = [];
        foreach (PlatformRolePermission::alias('role_permission')
            ->join(
                'permission permission',
                "permission.id = role_permission.permission_id AND permission.status = 'active' AND permission.key LIKE 'platform.%'",
            )->whereIn('role_permission.platform_role_id', $roleIds)
            ->field(['role_permission.platform_role_id', 'permission.key'])
            ->order('permission.key')->select()->toArray() as $permission) {
            $permissions[(int) $permission['platform_role_id']][] = (string) $permission['key'];
        }
        foreach ($rows as &$row) {
            $keys = array_values(array_unique($permissions[(int) $row['id']] ?? []));
            $row['permission_count'] = count($keys);
            if ($includeKeys) {
                $row['permission_keys'] = $keys;
            }
            $row = $this->normalize($row);
        }
        unset($row);

        return $rows;
    }

    private function applyAuditFilter(Query $query, GovernanceAuditFilter $filter): void
    {
        foreach ([
            'event_type' => $filter->eventType,
            'action' => $filter->action,
            'outcome' => $filter->outcome?->value,
            'request_id' => $filter->requestId,
            'target_type' => $filter->targetType,
            'target_id' => $filter->targetId,
        ] as $column => $value) {
            if ($value !== null) {
                $query->where('audit.' . $column, $value);
            }
        }
    }

    /** @return array<int|string, mixed> */
    private function auditFields(bool $withMetadata): array
    {
        $fields = [
            'audit.id', 'audit.event_type', 'audit.action', 'audit.outcome', 'audit.reason_code',
            'audit.operator_id', 'audit.account_id',
            'operator_label' => Db::raw("COALESCE(operator.display_name, account.display_name, 'platform_system')"),
            'audit.target_type', 'audit.target_id',
            'target_tenant_id' => Db::raw("CASE WHEN audit.target_type = 'tenant' THEN audit.target_id ELSE NULL END"),
            'audit.request_id', 'audit.operation_id', 'audit.occurred_at' => 'created_at',
        ];
        if ($withMetadata) {
            $fields[] = 'audit.metadata_json';
        }

        return $fields;
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

    /** @param array<string, mixed>|null $row
     * @return array<string, mixed>
     */
    private function requireRow(?array $row): array
    {
        if ($row === null) {
            throw AdminAccessException::notFound();
        }

        return $this->normalize($row);
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
        if (isset($row['permission_count'])) {
            $row['permission_count'] = (int) $row['permission_count'];
        }
        if (isset($row['is_builtin'])) {
            $row['is_builtin'] = (bool) $row['is_builtin'];
        }

        return $row;
    }
}
