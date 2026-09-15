<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Catalog;

use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Kernel\Authorization\Model\DataConditionDefinitionRecord;
use PeanutAdmin\Kernel\Authorization\Model\ProtectedResourceRecord;
use PeanutAdmin\Kernel\Authorization\Model\ResourceOperationPermissionRecord;
use PeanutAdmin\Kernel\Authorization\Model\ResourceOperationRecord;
use PeanutAdmin\Kernel\Authorization\Model\ResourceOperationTargetTypeRecord;
use PeanutAdmin\Kernel\Authorization\Model\TargetTypeRecord;
use PeanutAdmin\Kernel\Module\Model\ModuleInstallation;
use PeanutAdmin\Kernel\Module\Model\TenantModule;
use think\db\Raw;

final readonly class ThinkPhpResourceOperationCatalog implements ResourceOperationCatalog
{
    public function find(string $resourceKey, string $operation): ?ResourceOperation
    {
        $row = ResourceOperationRecord::alias('ro')
            ->join('protected_resource pr', "pr.id = ro.protected_resource_id AND pr.status = 'active'")
            ->where('pr.key', $resourceKey)
            ->where('ro.operation', $operation)
            ->where('ro.status', 'active')
            ->field([
                'ro.id', 'ro.protected_resource_id', 'ro.operation', 'ro.access_mode',
                'ro.target_cardinality', 'ro.permission_match', 'pr.key' => 'resource_key',
                'pr.module_key', 'pr.provider_key', 'pr.ownership',
            ])
            ->find()?->toArray();

        return $row === null ? null : $this->operation($row);
    }

    public function availableOperations(int $tenantId, PageRequest $page): array
    {
        $modules = ['core', ...TenantModule::alias('tm')
            ->join('module_installation mi', "mi.module_key = tm.module_key AND mi.status = 'active'")
            ->where('tm.tenant_id', $tenantId)
            ->where('tm.status', 'enabled')
            ->where(function ($query): void {
                $query->whereNull('tm.effective_at')->whereOr('tm.effective_at', '<=', new Raw('CURRENT_TIMESTAMP(3)'));
            })
            ->where(function ($query): void {
                $query->whereNull('tm.expires_at')->whereOr('tm.expires_at', '>', new Raw('CURRENT_TIMESTAMP(3)'));
            })
            ->column('tm.module_key')];
        $query = ResourceOperationRecord::alias('ro')
            ->join('protected_resource pr', "pr.id = ro.protected_resource_id AND pr.status = 'active'")
            ->where('ro.status', 'active')
            ->whereIn('pr.module_key', array_values(array_unique(array_map('strval', $modules))));
        $total = (clone $query)->count();
        if ($total === 0 || $page->page > intdiv((int) $total - 1, $page->pageSize) + 1) {
            return ['items' => [], 'total' => (int) $total];
        }
        $rows = $query->field([
            'ro.id', 'ro.protected_resource_id', 'ro.operation', 'ro.access_mode',
            'ro.target_cardinality', 'ro.permission_match', 'pr.key' => 'resource_key',
            'pr.module_key', 'pr.provider_key', 'pr.ownership',
        ])->order('pr.key')->order('ro.operation')->order('ro.id')
            ->page($page->page, $page->pageSize)
            ->select()
            ->toArray();

        return [
            'items' => array_values(array_map(fn(array $row): ResourceOperation => $this->operation($row), $rows)),
            'total' => (int) $total,
        ];
    }

    public function moduleAvailable(int $tenantId, string $moduleKey): bool
    {
        if ($moduleKey === 'core') {
            return true;
        }

        return TenantModule::alias('tm')
            ->join('module_installation mi', "mi.module_key = tm.module_key AND mi.status = 'active'")
            ->where('tm.tenant_id', $tenantId)
            ->where('tm.module_key', $moduleKey)
            ->where('tm.status', 'enabled')
            ->where(function ($query): void {
                $query->whereNull('tm.effective_at')->whereOr('tm.effective_at', '<=', new Raw('CURRENT_TIMESTAMP(3)'));
            })
            ->where(function ($query): void {
                $query->whereNull('tm.expires_at')->whereOr('tm.expires_at', '>', new Raw('CURRENT_TIMESTAMP(3)'));
            })
            ->count() > 0;
    }

    public function registryRevision(): string
    {
        $digests = [];
        foreach (ProtectedResourceRecord::field('id,status,manifest_digest')->select()->toArray() as $row) {
            $digests[] = "resource:{$row['id']}:{$row['status']}:{$row['manifest_digest']}";
        }
        foreach (ResourceOperationRecord::field('id,status,manifest_digest')->select()->toArray() as $row) {
            $digests[] = "operation:{$row['id']}:{$row['status']}:{$row['manifest_digest']}";
        }
        foreach (ModuleInstallation::field('module_key,status,revision,manifest_digest')->select()->toArray() as $row) {
            $digests[] = "module-installation:{$row['module_key']}:{$row['status']}:{$row['revision']}:{$row['manifest_digest']}";
        }
        foreach (TargetTypeRecord::field('id,status,manifest_digest')->select()->toArray() as $row) {
            $digests[] = "target:{$row['id']}:{$row['status']}:{$row['manifest_digest']}";
        }
        foreach (DataConditionDefinitionRecord::field('id,status,manifest_digest')->select()->toArray() as $row) {
            $digests[] = "condition:{$row['id']}:{$row['status']}:{$row['manifest_digest']}";
        }
        sort($digests, SORT_STRING);

        return hash('sha256', implode('|', $digests));
    }

    /** @param array<string, mixed> $row */
    private function operation(array $row): ResourceOperation
    {
        $id = (int) $row['id'];

        return new ResourceOperation(
            $id,
            (int) $row['protected_resource_id'],
            (string) $row['resource_key'],
            (string) $row['module_key'],
            (string) $row['provider_key'],
            (string) $row['ownership'],
            (string) $row['operation'],
            (string) $row['access_mode'],
            (string) $row['target_cardinality'],
            (string) $row['permission_match'],
            $this->permissionKeys($id),
            $this->targetTypes($id),
        );
    }

    /** @return list<string> */
    private function permissionKeys(int $operationId): array
    {
        return array_values(array_map('strval', ResourceOperationPermissionRecord::alias('relation')
            ->join('permission p', "p.id = relation.permission_id AND p.status = 'active'")
            ->where('relation.resource_operation_id', $operationId)
            ->order('relation.sort_order')
            ->order('p.key')
            ->column('p.key')));
    }

    /** @return list<OperationTargetType> */
    private function targetTypes(int $operationId): array
    {
        $rows = ResourceOperationTargetTypeRecord::alias('relation')
            ->join('target_type target', "target.id = relation.target_type_id AND target.status = 'active'")
            ->leftJoin('permission selection_permission', "selection_permission.id = relation.policy_selection_permission_id AND selection_permission.status = 'active'")
            ->where('relation.resource_operation_id', $operationId)
            ->where('relation.status', 'active')
            ->field([
                'relation.target_role', 'relation.input_mode', 'target.key', 'target.resolver_key',
                'target.catalog_provider_key', 'selection_permission.key' => 'policy_selection_permission_key',
            ])
            ->order('relation.target_role')
            ->order('target.key')
            ->select()
            ->toArray();

        return array_values(array_map(static fn(array $row): OperationTargetType => new OperationTargetType(
            (string) $row['target_role'],
            (string) $row['key'],
            (string) $row['resolver_key'],
            (string) $row['catalog_provider_key'],
            (string) $row['input_mode'],
            is_string($row['policy_selection_permission_key']) ? $row['policy_selection_permission_key'] : null,
        ), $rows));
    }
}
