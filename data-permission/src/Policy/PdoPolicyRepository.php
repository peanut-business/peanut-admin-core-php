<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Policy;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final readonly class PdoPolicyRepository implements PolicyRepository
{
    public function __construct(private PDO $pdo) {}

    public function revision(int $tenantId, int $memberId, int $operationId): PolicyRevision
    {
        $statement = $this->prepare(<<<'SQL'
SELECT
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
FROM pa_tenant t
JOIN pa_tenant_member tm ON tm.tenant_id = t.id AND tm.id = :member_id
LEFT JOIN pa_member_role member_role ON member_role.tenant_id = t.id AND member_role.tenant_member_id = tm.id
LEFT JOIN pa_role r ON r.tenant_id = t.id AND r.id = member_role.role_id
LEFT JOIN pa_data_permission_policy policy
  ON policy.tenant_id = t.id AND policy.role_id = r.id AND policy.resource_operation_id = :operation_id
LEFT JOIN pa_data_permission_group policy_group
  ON policy_group.tenant_id = t.id AND policy_group.data_permission_policy_id = policy.id
LEFT JOIN pa_data_permission_condition policy_condition
  ON policy_condition.tenant_id = t.id AND policy_condition.data_permission_group_id = policy_group.id
LEFT JOIN pa_data_permission_target_set target_set
  ON target_set.tenant_id = t.id AND target_set.id = policy_condition.target_set_id
WHERE t.id = :tenant_id
GROUP BY t.id, t.authorization_revision, tm.authorization_revision
SQL);
        $statement->execute([
            'tenant_id' => $tenantId,
            'member_id' => $memberId,
            'operation_id' => $operationId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return new PolicyRevision(hash('sha256', "missing:{$tenantId}:{$memberId}"), null);
        }
        $nextTransition = is_string($row['next_transition'])
            ? new DateTimeImmutable($row['next_transition'], new DateTimeZone('UTC'))
            : null;

        return new PolicyRevision(hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)), $nextTransition);
    }

    public function load(int $tenantId, int $memberId, int $operationId): EffectivePolicySet
    {
        $member = $this->prepare(<<<'SQL'
SELECT primary_department_id FROM pa_tenant_member
WHERE tenant_id = :tenant_id AND id = :member_id AND status = 'active'
SQL);
        $member->execute(['tenant_id' => $tenantId, 'member_id' => $memberId]);
        $memberRow = $member->fetch(PDO::FETCH_ASSOC);
        if (!is_array($memberRow)) {
            return new EffectivePolicySet([], null);
        }

        $statement = $this->prepare(<<<'SQL'
SELECT policy.id AS policy_id, policy.role_id,
       policy_group.id AS group_id,
       policy_condition.id AS condition_id,
       definition.`key` AS condition_key,
       target_set.id AS target_set_id,
       target_set.target_resource_key
FROM pa_member_role member_role
JOIN pa_role role
  ON role.tenant_id = member_role.tenant_id AND role.id = member_role.role_id AND role.status = 'active'
JOIN pa_data_permission_policy policy
  ON policy.tenant_id = member_role.tenant_id AND policy.role_id = role.id
 AND policy.resource_operation_id = :operation_id AND policy.status = 'active'
 AND (policy.valid_from IS NULL OR policy.valid_from <= CURRENT_TIMESTAMP(3))
 AND (policy.valid_until IS NULL OR policy.valid_until > CURRENT_TIMESTAMP(3))
JOIN pa_resource_operation catalog_operation
  ON catalog_operation.id = policy.resource_operation_id
 AND catalog_operation.protected_resource_id = policy.protected_resource_id
 AND catalog_operation.status = 'active'
JOIN pa_data_permission_group policy_group
  ON policy_group.tenant_id = policy.tenant_id
 AND policy_group.data_permission_policy_id = policy.id AND policy_group.status = 'active'
JOIN pa_data_permission_condition policy_condition
  ON policy_condition.tenant_id = policy_group.tenant_id
 AND policy_condition.data_permission_group_id = policy_group.id AND policy_condition.status = 'active'
JOIN pa_data_condition_definition definition
  ON definition.id = policy_condition.condition_definition_id AND definition.status = 'active'
LEFT JOIN pa_data_permission_target_set target_set
  ON target_set.tenant_id = policy_condition.tenant_id
 AND target_set.id = policy_condition.target_set_id AND target_set.status = 'active'
JOIN pa_resource_operation_condition allowed_condition
  ON allowed_condition.resource_operation_id = :allowed_operation_id
 AND allowed_condition.condition_definition_id = definition.id
 AND allowed_condition.status = 'active'
 AND (
     definition.`key` <> 'core.specified_objects'
     OR allowed_condition.selector_resource_key = target_set.target_resource_key
 )
WHERE member_role.tenant_id = :tenant_id AND member_role.tenant_member_id = :member_id
ORDER BY policy.id, policy_group.sort_order, policy_group.id, policy_condition.id
SQL);
        $statement->execute([
            'tenant_id' => $tenantId,
            'member_id' => $memberId,
            'operation_id' => $operationId,
            'allowed_operation_id' => $operationId,
        ]);

        /** @var array<int, array{policy_id: int, role_id: int, conditions: list<EffectiveCondition>}> $groups */
        $groups = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $groupId = (int) $row['group_id'];
            $groups[$groupId] ??= [
                'policy_id' => (int) $row['policy_id'],
                'role_id' => (int) $row['role_id'],
                'conditions' => [],
            ];
            $targetSetId = $row['target_set_id'] === null ? null : (int) $row['target_set_id'];
            [$targetIds, $targetCount] = $targetSetId === null
                ? [[], 0]
                : $this->targets($tenantId, $targetSetId);
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
            if ($group['conditions'] !== []) {
                $effectiveGroups[] = new EffectiveConditionGroup(
                    $group['policy_id'],
                    $group['role_id'],
                    $groupId,
                    $group['conditions'],
                );
            }
        }

        return new EffectivePolicySet(
            $effectiveGroups,
            $memberRow['primary_department_id'] === null ? null : (int) $memberRow['primary_department_id'],
        );
    }

    /** @return array{list<string>, int} */
    private function targets(int $tenantId, int $targetSetId): array
    {
        $countStatement = $this->prepare(<<<'SQL'
SELECT COUNT(*) FROM pa_data_permission_target
WHERE tenant_id = :tenant_id AND target_set_id = :target_set_id AND status = 'active'
SQL);
        $countStatement->execute(['tenant_id' => $tenantId, 'target_set_id' => $targetSetId]);
        $count = (int) $countStatement->fetchColumn();
        if ($count > 500) {
            return [[], $count];
        }

        $statement = $this->prepare(<<<'SQL'
SELECT target_id FROM pa_data_permission_target
WHERE tenant_id = :tenant_id AND target_set_id = :target_set_id AND status = 'active'
ORDER BY target_id
SQL);
        $statement->execute(['tenant_id' => $tenantId, 'target_set_id' => $targetSetId]);

        return [array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN))), $count];
    }

    private function prepare(string $sql): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Could not prepare the data-permission query.');
        }

        return $statement;
    }
}
