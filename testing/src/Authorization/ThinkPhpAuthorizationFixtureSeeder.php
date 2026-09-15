<?php

declare(strict_types=1);

namespace PeanutAdmin\Testing\Authorization;

use RuntimeException;
use think\facade\Db;

final readonly class ThinkPhpAuthorizationFixtureSeeder
{
    public function roleForMember(int $tenantId, int $memberId): int
    {
        $roleIds = Db::name('member_role')
            ->where('tenant_id', $tenantId)
            ->where('tenant_member_id', $memberId)
            ->column('role_id');
        $roleId = $roleIds === [] ? null : Db::name('role')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $roleIds)
            ->where('status', 'active')
            ->order('id')
            ->value('id');
        if ($roleId === null) {
            throw new RuntimeException('The fixture member has no active role.');
        }

        return (int) $roleId;
    }

    /** @param list<string> $permissionKeys */
    public function grantPermissions(int $tenantId, int $roleId, array $permissionKeys): void
    {
        foreach (array_values(array_unique($permissionKeys)) as $permissionKey) {
            $permissionId = Db::name('permission')
                ->where('key', $permissionKey)
                ->where('status', 'active')
                ->value('id');
            if ($permissionId === null) {
                throw new RuntimeException("Fixture permission does not exist: {$permissionKey}");
            }
            $exists = Db::name('role_permission')
                ->where('tenant_id', $tenantId)
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->find();
            if ($exists === null) {
                Db::name('role_permission')->insert([
                    'tenant_id' => $tenantId,
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'granted_at' => gmdate('Y-m-d H:i:s.000'),
                ]);
            }
        }
        $this->bumpAuthorizationRevision($tenantId, $roleId);
    }

    /** @param non-empty-list<string> $targetIds */
    public function targetSet(int $tenantId, int $memberId, string $resourceKey, array $targetIds): int
    {
        $now = gmdate('Y-m-d H:i:s.000');
        $targetSetId = (int) Db::name('data_permission_target_set')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => 'Fixture ' . $resourceKey . ' ' . bin2hex(random_bytes(4)),
            'target_mode' => 'resource',
            'target_resource_key' => $resourceKey,
            'created_by_member_id' => $memberId,
            'updated_by_member_id' => $memberId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('data_permission_target')->insertAll(array_map(
            static fn(string $targetId): array => [
                'tenant_id' => $tenantId,
                'target_set_id' => $targetSetId,
                'target_id' => $targetId,
                'added_by_member_id' => $memberId,
                'added_at' => $now,
            ],
            array_values(array_unique($targetIds, SORT_STRING)),
        ));

        return $targetSetId;
    }

    /** @param non-empty-list<array<string, int>> $targetGroups */
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
                $now = gmdate('Y-m-d H:i:s.000');
                Db::name('data_permission_condition')->insert([
                    'tenant_id' => $tenantId,
                    'data_permission_group_id' => $groupId,
                    'condition_definition_id' => $conditionId,
                    'target_set_id' => $targetSetId,
                    'created_at' => $now,
                    'updated_at' => $now,
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
        $now = gmdate('Y-m-d H:i:s.000');
        Db::name('data_permission_condition')->insert([
            'tenant_id' => $tenantId,
            'data_permission_group_id' => $this->group($tenantId, $policyId, 0),
            'condition_definition_id' => $this->conditionId('core.tenant_all'),
            'target_set_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->bumpAuthorizationRevision($tenantId, $roleId);
    }

    /** @return array{int, int} */
    private function operation(string $resourceKey, string $operation): array
    {
        $resourceId = Db::name('protected_resource')
            ->where('key', $resourceKey)
            ->where('status', 'active')
            ->value('id');
        $operationId = $resourceId === null ? null : Db::name('resource_operation')
            ->where('protected_resource_id', $resourceId)
            ->where('operation', $operation)
            ->where('status', 'active')
            ->value('id');
        if ($resourceId === null || $operationId === null) {
            throw new RuntimeException("Fixture operation does not exist: {$resourceKey}:{$operation}");
        }

        return [(int) $resourceId, (int) $operationId];
    }

    private function policy(
        int $tenantId,
        int $roleId,
        int $memberId,
        int $resourceId,
        int $operationId,
    ): int {
        $now = gmdate('Y-m-d H:i:s.000');
        return (int) Db::name('data_permission_policy')->insertGetId([
            'tenant_id' => $tenantId,
            'role_id' => $roleId,
            'protected_resource_id' => $resourceId,
            'resource_operation_id' => $operationId,
            'created_by_member_id' => $memberId,
            'updated_by_member_id' => $memberId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function group(int $tenantId, int $policyId, int $index): int
    {
        $now = gmdate('Y-m-d H:i:s.000');
        return (int) Db::name('data_permission_group')->insertGetId([
            'tenant_id' => $tenantId,
            'data_permission_policy_id' => $policyId,
            'name' => 'Fixture group ' . ($index + 1),
            'sort_order' => $index,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function conditionId(string $key): int
    {
        $id = Db::name('data_condition_definition')
            ->where('key', $key)
            ->where('status', 'active')
            ->value('id');
        if ($id === null) {
            throw new RuntimeException("Fixture condition does not exist: {$key}");
        }

        return (int) $id;
    }

    private function bumpAuthorizationRevision(int $tenantId, int $roleId): void
    {
        $now = gmdate('Y-m-d H:i:s.000');
        Db::name('role')->where('tenant_id', $tenantId)->where('id', $roleId)->update([
            'authorization_revision' => Db::raw('authorization_revision + 1'),
            'updated_at' => $now,
        ]);
        Db::name('tenant')->where('id', $tenantId)->update([
            'authorization_revision' => Db::raw('authorization_revision + 1'),
            'updated_at' => $now,
        ]);
    }
}
