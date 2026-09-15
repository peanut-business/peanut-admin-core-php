<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Policy;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\DataPermission\Model\DataPermissionTargetRecord;
use PeanutAdmin\Kernel\Persistence\Model\MemberRole;
use PeanutAdmin\Kernel\Persistence\Model\Tenant;
use PeanutAdmin\Kernel\Persistence\Model\TenantMember;
use think\db\Raw;

final readonly class ThinkPhpPolicyRepository implements PolicyRepository
{
    public function revision(int $tenantId, int $memberId, int $operationId): PolicyRevision
    {
        $row = Tenant::alias('t')
            ->join('tenant_member tm', 'tm.tenant_id = t.id')
            ->leftJoin('member_role member_role', 'member_role.tenant_id = t.id AND member_role.tenant_member_id = tm.id')
            ->leftJoin('role r', 'r.tenant_id = t.id AND r.id = member_role.role_id')
            ->leftJoin(
                'data_permission_policy policy',
                'policy.tenant_id = t.id AND policy.role_id = r.id AND policy.resource_operation_id = ' . $operationId,
            )
            ->leftJoin('data_permission_group policy_group', 'policy_group.tenant_id = t.id AND policy_group.data_permission_policy_id = policy.id')
            ->leftJoin('data_permission_condition policy_condition', 'policy_condition.tenant_id = t.id AND policy_condition.data_permission_group_id = policy_group.id')
            ->leftJoin('data_permission_target_set target_set', 'target_set.tenant_id = t.id AND target_set.id = policy_condition.target_set_id')
            ->where('t.id', $tenantId)
            ->where('tm.id', $memberId)
            ->fieldRaw(<<<'SQL'
t.authorization_revision AS tenant_revision,
tm.authorization_revision AS member_revision,
COALESCE(GROUP_CONCAT(DISTINCT CONCAT(
    r.id, ':', r.authorization_revision, ':', r.status, ':',
    policy.id, ':', policy.revision, ':', policy.status, ':',
    COALESCE(policy.valid_from, ''), ':', COALESCE(policy.valid_until, ''), ':',
    policy_group.id, ':', policy_group.revision, ':', policy_group.status, ':',
    policy_condition.id, ':', policy_condition.revision, ':', policy_condition.status, ':',
    COALESCE(target_set.id, 0), ':', COALESCE(target_set.revision, 0), ':', COALESCE(target_set.status, '')
) ORDER BY r.id, policy.id, policy_group.id, policy_condition.id SEPARATOR '|'), '') AS policy_revisions,
MIN(CASE
    WHEN policy.valid_from > CURRENT_TIMESTAMP(3) THEN policy.valid_from
    WHEN policy.valid_until > CURRENT_TIMESTAMP(3) THEN policy.valid_until
    ELSE NULL
END) AS next_transition
SQL)
            ->group('t.id,t.authorization_revision,tm.authorization_revision')
            ->find()?->toArray();
        if ($row === null) {
            return new PolicyRevision(hash('sha256', "missing:{$tenantId}:{$memberId}"), null);
        }
        $nextTransition = is_string($row['next_transition'])
            ? new DateTimeImmutable($row['next_transition'], new DateTimeZone('UTC'))
            : null;

        return new PolicyRevision(hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)), $nextTransition);
    }

    public function load(int $tenantId, int $memberId, int $operationId): EffectivePolicySet
    {
        $memberRow = TenantMember::where('tenant_id', $tenantId)
            ->where('id', $memberId)
            ->where('status', 'active')
            ->field('primary_department_id')
            ->find()?->toArray();
        if ($memberRow === null) {
            return new EffectivePolicySet([], null);
        }

        $rows = MemberRole::alias('member_role')
            ->join('role role', "role.tenant_id = member_role.tenant_id AND role.id = member_role.role_id AND role.status = 'active'")
            ->join('data_permission_policy policy', "policy.tenant_id = member_role.tenant_id AND policy.role_id = role.id AND policy.status = 'active'")
            ->join('resource_operation catalog_operation', "catalog_operation.id = policy.resource_operation_id AND catalog_operation.protected_resource_id = policy.protected_resource_id AND catalog_operation.status = 'active'")
            ->join('data_permission_group policy_group', "policy_group.tenant_id = policy.tenant_id AND policy_group.data_permission_policy_id = policy.id AND policy_group.status = 'active'")
            ->join('data_permission_condition policy_condition', "policy_condition.tenant_id = policy_group.tenant_id AND policy_condition.data_permission_group_id = policy_group.id AND policy_condition.status = 'active'")
            ->join('data_condition_definition definition', "definition.id = policy_condition.condition_definition_id AND definition.status = 'active'")
            ->leftJoin('data_permission_target_set target_set', "target_set.tenant_id = policy_condition.tenant_id AND target_set.id = policy_condition.target_set_id AND target_set.status = 'active'")
            ->join('resource_operation_condition allowed_condition', "allowed_condition.resource_operation_id = policy.resource_operation_id AND allowed_condition.condition_definition_id = definition.id AND allowed_condition.status = 'active'")
            ->where('member_role.tenant_id', $tenantId)
            ->where('member_role.tenant_member_id', $memberId)
            ->where('policy.resource_operation_id', $operationId)
            ->where(function ($query): void {
                $query->whereNull('policy.valid_from')->whereOr('policy.valid_from', '<=', new Raw('CURRENT_TIMESTAMP(3)'));
            })
            ->where(function ($query): void {
                $query->whereNull('policy.valid_until')->whereOr('policy.valid_until', '>', new Raw('CURRENT_TIMESTAMP(3)'));
            })
            ->where(function ($query): void {
                $query->where('definition.key', '<>', 'core.specified_objects')
                    ->whereOr('allowed_condition.selector_resource_key', '=', new Raw('target_set.target_resource_key'));
            })
            ->field([
                'policy.id' => 'policy_id', 'policy.role_id', 'policy_group.id' => 'group_id',
                'policy_condition.id' => 'condition_id', 'definition.key' => 'condition_key',
                'target_set.id' => 'target_set_id', 'target_set.target_resource_key',
            ])
            ->order('policy.id')
            ->order('policy_group.sort_order')
            ->order('policy_group.id')
            ->order('policy_condition.id')
            ->select()
            ->toArray();

        $groups = [];
        foreach ($rows as $row) {
            $groupId = (int) $row['group_id'];
            $groups[$groupId] ??= [
                'policy_id' => (int) $row['policy_id'],
                'role_id' => (int) $row['role_id'],
                'conditions' => [],
            ];
            $targetSetId = $row['target_set_id'] === null ? null : (int) $row['target_set_id'];
            [$targetIds, $targetCount] = $targetSetId === null ? [[], 0] : $this->targets($tenantId, $targetSetId);
            $groups[$groupId]['conditions'][] = new EffectiveCondition(
                (int) $row['condition_id'],
                (string) $row['condition_key'],
                $targetSetId,
                is_string($row['target_resource_key']) ? $row['target_resource_key'] : null,
                $targetIds,
                $targetCount,
            );
        }

        $effectiveGroups = [];
        foreach ($groups as $groupId => $group) {
            $effectiveGroups[] = new EffectiveConditionGroup(
                $group['policy_id'],
                $group['role_id'],
                $groupId,
                $group['conditions'],
            );
        }

        return new EffectivePolicySet(
            $effectiveGroups,
            $memberRow['primary_department_id'] === null ? null : (int) $memberRow['primary_department_id'],
        );
    }

    /** @return array{list<string>, int} */
    private function targets(int $tenantId, int $targetSetId): array
    {
        $query = DataPermissionTargetRecord::where('tenant_id', $tenantId)
            ->where('target_set_id', $targetSetId)
            ->where('status', 'active');
        $count = (clone $query)->count();
        if ($count > 500) {
            return [[], (int) $count];
        }

        return [array_values(array_map('strval', $query->order('target_id')->column('target_id'))), (int) $count];
    }
}
