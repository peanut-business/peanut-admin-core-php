<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Application;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;
use PDOStatement;
use PeanutAdmin\DataPermission\Target\TargetResolverRegistry;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetSet;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use Throwable;

final readonly class DataPolicyAdminService
{
    public function __construct(
        private PDO $pdo,
        private TargetResolverRegistry $targetResolvers,
    ) {}

    /** @return array<string, mixed> */
    public function get(int $tenantId, int $roleId, string $resourceKey, string $operation): array
    {
        $catalog = $this->operation($tenantId, $resourceKey, $operation);
        $this->requireRole($tenantId, $roleId, false);
        $policy = $this->fetchOne(<<<'SQL'
SELECT id FROM pa_data_permission_policy
WHERE tenant_id = :tenant_id AND role_id = :role_id AND resource_operation_id = :operation_id
SQL, [
            'tenant_id' => $tenantId,
            'role_id' => $roleId,
            'operation_id' => (int) $catalog['operation_id'],
        ]);
        if ($policy === null) {
            throw AdminAccessException::notFound();
        }

        return $this->policy((int) $policy['id']);
    }

    public function targetCardinality(int $tenantId, string $resourceKey, string $operation): string
    {
        return (string) $this->operation($tenantId, $resourceKey, $operation)['target_cardinality'];
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function replace(
        TenantContext $actor,
        int $roleId,
        string $resourceKey,
        string $operation,
        array $payload,
        ?int $expectedRevision,
    ): array {
        $input = $this->validatePayload($payload);

        return $this->transaction(function () use (
            $actor,
            $roleId,
            $resourceKey,
            $operation,
            $input,
            $expectedRevision,
        ): array {
            $this->tenant($actor->tenantId, true);
            $role = $this->requireRole($actor->tenantId, $roleId, true);
            $catalog = $this->operation($actor->tenantId, $resourceKey, $operation);
            $existing = $this->fetchOne(<<<'SQL'
SELECT * FROM pa_data_permission_policy
WHERE tenant_id = :tenant_id AND role_id = :role_id AND resource_operation_id = :operation_id
FOR UPDATE
SQL, [
                'tenant_id' => $actor->tenantId,
                'role_id' => $roleId,
                'operation_id' => (int) $catalog['operation_id'],
            ]);
            if ($existing !== null) {
                if ($expectedRevision === null) {
                    throw AdminAccessException::preconditionRequired();
                }
                if ((int) $existing['revision'] !== $expectedRevision) {
                    throw AdminAccessException::revisionMismatch();
                }
            } elseif ($expectedRevision !== null) {
                throw AdminAccessException::revisionMismatch();
            }

            $allowedConditions = $this->allowedConditions((int) $catalog['operation_id']);
            $preparedGroups = $this->prepareGroups($actor, $input['groups'], $allowedConditions);
            $now = $this->now();
            if ($existing === null) {
                $this->execute(<<<'SQL'
INSERT INTO pa_data_permission_policy (
    tenant_id, role_id, protected_resource_id, resource_operation_id,
    status, valid_from, valid_until, revision, reason,
    created_by_member_id, updated_by_member_id, created_at, updated_at
) VALUES (
    :tenant_id, :role_id, :resource_id, :operation_id,
    :status, :valid_from, :valid_until, 1, :reason,
    :member_id, :member_id_again, :created_at, :updated_at
)
SQL, [
                    'tenant_id' => $actor->tenantId,
                    'role_id' => $roleId,
                    'resource_id' => (int) $catalog['resource_id'],
                    'operation_id' => (int) $catalog['operation_id'],
                    'status' => $input['status'],
                    'valid_from' => $input['valid_from'],
                    'valid_until' => $input['valid_until'],
                    'reason' => $input['reason'],
                    'member_id' => $actor->memberId,
                    'member_id_again' => $actor->memberId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $policyId = (int) $this->pdo->lastInsertId();
            } else {
                $policyId = (int) $existing['id'];
                $this->deletePolicyChildren($actor->tenantId, $policyId);
                if ($this->execute(<<<'SQL'
UPDATE pa_data_permission_policy
SET status = :status, valid_from = :valid_from, valid_until = :valid_until,
    reason = :reason, revision = revision + 1,
    updated_by_member_id = :member_id, updated_at = :updated_at
WHERE tenant_id = :tenant_id AND id = :policy_id AND revision = :expected_revision
SQL, [
                    'status' => $input['status'],
                    'valid_from' => $input['valid_from'],
                    'valid_until' => $input['valid_until'],
                    'reason' => $input['reason'],
                    'member_id' => $actor->memberId,
                    'updated_at' => $now,
                    'tenant_id' => $actor->tenantId,
                    'policy_id' => $policyId,
                    'expected_revision' => $expectedRevision,
                ]) !== 1) {
                    throw AdminAccessException::revisionMismatch();
                }
            }
            $targetAuditKeys = $this->insertGroups(
                $actor->tenantId,
                $actor->memberId,
                $policyId,
                $preparedGroups,
                $now,
            );
            $this->execute(<<<'SQL'
UPDATE pa_role
SET authorization_revision = authorization_revision + 1, updated_at = :updated_at
WHERE tenant_id = :tenant_id AND id = :role_id AND authorization_revision = :expected_revision
SQL, [
                'updated_at' => $now,
                'tenant_id' => $actor->tenantId,
                'role_id' => $roleId,
                'expected_revision' => (int) $role['authorization_revision'],
            ]);
            $this->execute(<<<'SQL'
UPDATE pa_tenant
SET authorization_revision = authorization_revision + 1, updated_at = :updated_at
WHERE id = :tenant_id
SQL, ['updated_at' => $now, 'tenant_id' => $actor->tenantId]);
            $this->audit(
                $actor,
                $policyId,
                $roleId,
                $resourceKey,
                $operation,
                $targetAuditKeys,
                $now,
            );

            return $this->policy($policyId);
        });
    }

    /** @param array<string, mixed> $payload
     * @return array{status: string, reason: string|null, valid_from: string|null, valid_until: string|null, groups: list<array<string, mixed>>}
     */
    private function validatePayload(array $payload): array
    {
        $this->assertKeys($payload, ['status', 'reason', 'valid_from', 'valid_until', 'groups']);
        $status = $payload['status'] ?? null;
        if (!is_string($status) || !in_array($status, ['active', 'disabled'], true)) {
            throw AdminAccessException::invalid('DATA_POLICY_STATUS_INVALID', 'Policy status must be active or disabled.');
        }
        $reason = $this->optionalText($payload['reason'] ?? null, 300, 'DATA_POLICY_REASON_INVALID');
        $validFrom = $this->date($payload['valid_from'] ?? null, 'valid_from');
        $validUntil = $this->date($payload['valid_until'] ?? null, 'valid_until');
        if ($validFrom !== null && $validUntil !== null && $validUntil <= $validFrom) {
            throw AdminAccessException::invalid('DATA_POLICY_PERIOD_INVALID', 'valid_until must be later than valid_from.');
        }
        $groups = $payload['groups'] ?? null;
        if (!is_array($groups) || !array_is_list($groups) || count($groups) > 50) {
            throw AdminAccessException::invalid('DATA_POLICY_GROUPS_INVALID', 'Policy groups must be a list of at most 50 items.');
        }
        if ($status === 'active' && $groups === []) {
            throw AdminAccessException::invalid('DATA_POLICY_GROUPS_INVALID', 'An active policy requires at least one group.');
        }
        foreach ($groups as $group) {
            if (!is_array($group)) {
                throw AdminAccessException::invalid('DATA_POLICY_GROUP_INVALID', 'Each policy group must be an object.');
            }
        }

        return [
            'status' => $status,
            'reason' => $reason,
            'valid_from' => $validFrom?->format('Y-m-d H:i:s.v'),
            'valid_until' => $validUntil?->format('Y-m-d H:i:s.v'),
            'groups' => $groups,
        ];
    }

    /** @param list<array<string, mixed>> $groups
     * @param array<string, list<array<string, mixed>>> $allowedConditions
     * @return list<array{name: string, conditions: list<array{definition_id: int, condition_key: string, target_mode: string, target_set: array{name: string, target_resource_key: string, target_ids: list<string>}|null}>}>
     */
    private function prepareGroups(TenantContext $actor, array $groups, array $allowedConditions): array
    {
        $prepared = [];
        $names = [];
        foreach ($groups as $group) {
            $this->assertKeys($group, ['name', 'conditions']);
            $name = $this->requiredText($group['name'] ?? null, 120, 'DATA_POLICY_GROUP_INVALID');
            if (isset($names[$name])) {
                throw AdminAccessException::invalid('DATA_POLICY_GROUP_INVALID', 'Policy group names must be unique.');
            }
            $names[$name] = true;
            $conditions = $group['conditions'] ?? null;
            if (!is_array($conditions) || !array_is_list($conditions) || $conditions === [] || count($conditions) > 20) {
                throw AdminAccessException::invalid(
                    'DATA_POLICY_CONDITIONS_INVALID',
                    'Each group requires between 1 and 20 conditions.',
                );
            }
            $preparedConditions = [];
            foreach ($conditions as $condition) {
                $preparedConditions[] = $this->prepareCondition($actor, $condition, $allowedConditions);
            }
            $prepared[] = ['name' => $name, 'conditions' => $preparedConditions];
        }

        return $prepared;
    }

    /** @param mixed $condition
     * @param array<string, list<array<string, mixed>>> $allowedConditions
     * @return array{definition_id: int, condition_key: string, target_mode: string, target_set: array{name: string, target_resource_key: string, target_ids: list<string>}|null}
     */
    private function prepareCondition(TenantContext $actor, mixed $condition, array $allowedConditions): array
    {
        if (!is_array($condition)) {
            throw AdminAccessException::invalid('DATA_POLICY_CONDITION_INVALID', 'Each condition must be an object.');
        }
        $this->assertKeys($condition, ['condition_key', 'target_set', 'config']);
        $conditionKey = $condition['condition_key'] ?? null;
        if (!is_string($conditionKey) || !isset($allowedConditions[$conditionKey])) {
            throw AdminAccessException::invalid(
                'DATA_POLICY_CONDITION_INVALID',
                'The condition is not allowed for this resource operation.',
            );
        }
        $config = $condition['config'] ?? null;
        if ($config !== null && $config !== []) {
            throw AdminAccessException::invalid(
                'DATA_POLICY_CONFIG_INVALID',
                'P0 conditions do not accept arbitrary configuration fields.',
            );
        }
        $targetSet = $condition['target_set'] ?? null;
        $definition = null;
        if ($targetSet === null) {
            foreach ($allowedConditions[$conditionKey] as $candidate) {
                if ($candidate['target_mode'] === 'none' && $candidate['selector_resource_key'] === null) {
                    $definition = $candidate;
                    break;
                }
            }
            if ($definition === null) {
                throw AdminAccessException::invalid(
                    'DATA_POLICY_TARGET_SET_REQUIRED',
                    'The condition requires a typed target set.',
                );
            }
        } else {
            if (!is_array($targetSet)) {
                throw AdminAccessException::invalid('DATA_POLICY_TARGET_SET_INVALID', 'target_set must be an object.');
            }
            $this->assertKeys($targetSet, ['name', 'target_resource_key', 'targets']);
            $targetResourceKey = $targetSet['target_resource_key'] ?? null;
            if (!is_string($targetResourceKey) || $targetResourceKey === '') {
                throw AdminAccessException::invalid('DATA_POLICY_TARGET_SET_INVALID', 'target_resource_key is required.');
            }
            foreach ($allowedConditions[$conditionKey] as $candidate) {
                $expectedTarget = $candidate['target_mode'] === 'department'
                    ? 'core.department'
                    : $candidate['selector_resource_key'];
                if ($expectedTarget === $targetResourceKey) {
                    $definition = $candidate;
                    break;
                }
            }
            if ($definition === null || $definition['target_mode'] === 'none') {
                throw AdminAccessException::invalid(
                    'DATA_POLICY_TARGET_TYPE_MISMATCH',
                    'The target set type is not allowed for this condition.',
                );
            }
            $targetSet = $this->prepareTargetSet($actor, $targetSet, (string) $definition['target_mode']);
        }

        return [
            'definition_id' => (int) $definition['definition_id'],
            'condition_key' => $conditionKey,
            'target_mode' => (string) $definition['target_mode'],
            'target_set' => $targetSet,
        ];
    }

    /** @param array<string, mixed> $targetSet
     * @return array{name: string, target_resource_key: string, target_ids: list<string>}
     */
    private function prepareTargetSet(TenantContext $actor, array $targetSet, string $targetMode): array
    {
        $name = $this->requiredText($targetSet['name'] ?? null, 120, 'DATA_POLICY_TARGET_SET_INVALID');
        $targetResourceKey = (string) $targetSet['target_resource_key'];
        $targets = $targetSet['targets'] ?? null;
        if (!is_array($targets) || !array_is_list($targets) || $targets === [] || count($targets) > 500) {
            throw AdminAccessException::invalid(
                'DATA_POLICY_TARGETS_INVALID',
                'A target set requires between 1 and 500 targets.',
            );
        }
        $targetIds = [];
        foreach ($targets as $target) {
            if (!is_array($target)) {
                throw AdminAccessException::invalid('DATA_POLICY_TARGETS_INVALID', 'Each target must be an object.');
            }
            $this->assertKeys($target, ['target_id']);
            $targetId = $target['target_id'] ?? null;
            if (!is_string($targetId) || trim($targetId) === '' || strlen($targetId) > 128) {
                throw AdminAccessException::invalid('DATA_POLICY_TARGETS_INVALID', 'Each target_id must be a string.');
            }
            $targetIds[] = trim($targetId);
        }
        $targetIds = array_values(array_unique($targetIds, SORT_STRING));
        if (count($targetIds) !== count($targets)) {
            throw AdminAccessException::invalid('DATA_POLICY_TARGETS_INVALID', 'Target IDs must be unique.');
        }
        if ($targetMode === 'department') {
            $this->validateDepartments($actor->tenantId, $targetIds);
        } else {
            $targetType = $this->fetchOne(<<<'SQL'
SELECT resolver_key, module_key FROM pa_target_type
WHERE `key` = :target_resource_key AND status = 'active'
SQL, ['target_resource_key' => $targetResourceKey]);
            if ($targetType === null || !$this->moduleAvailable($actor->tenantId, (string) $targetType['module_key'])) {
                throw AdminAccessException::invalid(
                    'DATA_POLICY_TARGET_TYPE_MISMATCH',
                    'The target type is unavailable for this tenant.',
                );
            }
            $this->targetResolvers->get((string) $targetType['resolver_key'])->resolveAndValidate(
                $actor,
                new TypedResourceTargetSet($targetResourceKey, $targetIds),
            );
        }

        return ['name' => $name, 'target_resource_key' => $targetResourceKey, 'target_ids' => $targetIds];
    }

    /** @param list<string> $targetIds */
    private function validateDepartments(int $tenantId, array $targetIds): void
    {
        $placeholders = implode(', ', array_fill(0, count($targetIds), '?'));
        $statement = $this->statement(
            "SELECT COUNT(*) FROM pa_department WHERE tenant_id = ? AND status = 'active' AND id IN ({$placeholders})",
        );
        $statement->execute([$tenantId, ...$targetIds]);
        if ((int) $statement->fetchColumn() !== count($targetIds)) {
            throw AdminAccessException::invalid(
                'AUTHZ_TARGET_NOT_FOUND',
                'A selected department does not exist in the tenant.',
            );
        }
    }

    /** @param list<array{name: string, conditions: list<array{definition_id: int, condition_key: string, target_mode: string, target_set: array{name: string, target_resource_key: string, target_ids: list<string>}|null}>}> $groups
     * @return list<string>
     */
    private function insertGroups(
        int $tenantId,
        int $memberId,
        int $policyId,
        array $groups,
        string $now,
    ): array {
        $auditKeys = [];
        foreach ($groups as $sortOrder => $group) {
            $this->execute(<<<'SQL'
INSERT INTO pa_data_permission_group (
    tenant_id, data_permission_policy_id, name, match_mode, sort_order, status, created_at, updated_at
) VALUES (
    :tenant_id, :policy_id, :name, 'all', :sort_order, 'active', :created_at, :updated_at
)
SQL, [
                'tenant_id' => $tenantId,
                'policy_id' => $policyId,
                'name' => $group['name'],
                'sort_order' => $sortOrder,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $groupId = (int) $this->pdo->lastInsertId();
            foreach ($group['conditions'] as $condition) {
                $targetSetId = null;
                if ($condition['target_set'] !== null) {
                    $targetSet = $condition['target_set'];
                    $this->execute(<<<'SQL'
INSERT INTO pa_data_permission_target_set (
    tenant_id, name, target_mode, target_resource_key, status,
    created_by_member_id, updated_by_member_id, created_at, updated_at
) VALUES (
    :tenant_id, :name, :target_mode, :target_resource_key, 'active',
    :member_id, :member_id_again, :created_at, :updated_at
)
SQL, [
                        'tenant_id' => $tenantId,
                        'name' => $targetSet['name'],
                        'target_mode' => $condition['target_mode'],
                        'target_resource_key' => $targetSet['target_resource_key'],
                        'member_id' => $memberId,
                        'member_id_again' => $memberId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $targetSetId = (int) $this->pdo->lastInsertId();
                    foreach ($targetSet['target_ids'] as $targetId) {
                        $this->execute(<<<'SQL'
INSERT INTO pa_data_permission_target (
    tenant_id, target_set_id, target_id, status, added_by_member_id, added_at
) VALUES (:tenant_id, :target_set_id, :target_id, 'active', :member_id, :added_at)
SQL, [
                            'tenant_id' => $tenantId,
                            'target_set_id' => $targetSetId,
                            'target_id' => $targetId,
                            'member_id' => $memberId,
                            'added_at' => $now,
                        ]);
                        $auditKeys[] = $targetSet['target_resource_key'] . ':' . $targetId;
                    }
                }
                $this->execute(<<<'SQL'
INSERT INTO pa_data_permission_condition (
    tenant_id, data_permission_group_id, condition_definition_id,
    target_set_id, config_json, status, created_at, updated_at
) VALUES (
    :tenant_id, :group_id, :definition_id, :target_set_id, NULL, 'active', :created_at, :updated_at
)
SQL, [
                    'tenant_id' => $tenantId,
                    'group_id' => $groupId,
                    'definition_id' => $condition['definition_id'],
                    'target_set_id' => $targetSetId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        sort($auditKeys, SORT_STRING);

        return $auditKeys;
    }

    private function deletePolicyChildren(int $tenantId, int $policyId): void
    {
        $statement = $this->statement(<<<'SQL'
SELECT DISTINCT condition_row.target_set_id
FROM pa_data_permission_group group_row
JOIN pa_data_permission_condition condition_row
  ON condition_row.tenant_id = group_row.tenant_id
 AND condition_row.data_permission_group_id = group_row.id
WHERE group_row.tenant_id = :tenant_id
  AND group_row.data_permission_policy_id = :policy_id
  AND condition_row.target_set_id IS NOT NULL
SQL);
        $statement->execute(['tenant_id' => $tenantId, 'policy_id' => $policyId]);
        $targetSetIds = array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
        if ($targetSetIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($targetSetIds), '?'));
            $this->statement(
                "DELETE FROM pa_data_permission_target WHERE tenant_id = ? AND target_set_id IN ({$placeholders})",
            )->execute([$tenantId, ...$targetSetIds]);
        }
        $groupIds = $this->groupIds($tenantId, $policyId);
        if ($groupIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($groupIds), '?'));
            $this->statement(
                "DELETE FROM pa_data_permission_condition WHERE tenant_id = ? AND data_permission_group_id IN ({$placeholders})",
            )->execute([$tenantId, ...$groupIds]);
        }
        if ($targetSetIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($targetSetIds), '?'));
            $this->statement(
                "DELETE FROM pa_data_permission_target_set WHERE tenant_id = ? AND id IN ({$placeholders})",
            )->execute([$tenantId, ...$targetSetIds]);
        }
        $this->execute(<<<'SQL'
DELETE FROM pa_data_permission_group
WHERE tenant_id = :tenant_id AND data_permission_policy_id = :policy_id
SQL, ['tenant_id' => $tenantId, 'policy_id' => $policyId]);
    }

    /** @return list<int> */
    private function groupIds(int $tenantId, int $policyId): array
    {
        $statement = $this->statement(<<<'SQL'
SELECT id FROM pa_data_permission_group
WHERE tenant_id = :tenant_id AND data_permission_policy_id = :policy_id
ORDER BY id
SQL);
        $statement->execute(['tenant_id' => $tenantId, 'policy_id' => $policyId]);

        return array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function allowedConditions(int $operationId): array
    {
        $statement = $this->statement(<<<'SQL'
SELECT definition.id AS definition_id, definition.`key` AS condition_key,
       definition.target_mode, definition.config_schema_json,
       allowed.selector_resource_key
FROM pa_resource_operation_condition allowed
JOIN pa_data_condition_definition definition
  ON definition.id = allowed.condition_definition_id AND definition.status = 'active'
WHERE allowed.resource_operation_id = :operation_id AND allowed.status = 'active'
ORDER BY definition.`key`, allowed.selector_resource_key
SQL);
        $statement->execute(['operation_id' => $operationId]);
        $conditions = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $conditions[(string) $row['condition_key']][] = $row;
        }

        return $conditions;
    }

    /** @return array<string, mixed> */
    private function operation(int $tenantId, string $resourceKey, string $operation): array
    {
        $row = $this->fetchOne(<<<'SQL'
SELECT resource.id AS resource_id, resource.module_key,
       operation_row.id AS operation_id, operation_row.target_cardinality
FROM pa_protected_resource resource
JOIN pa_resource_operation operation_row
  ON operation_row.protected_resource_id = resource.id AND operation_row.status = 'active'
WHERE resource.`key` = :resource_key AND resource.status = 'active'
  AND operation_row.operation = :operation
SQL, ['resource_key' => $resourceKey, 'operation' => $operation]);
        if ($row === null || !$this->moduleAvailable($tenantId, (string) $row['module_key'])) {
            throw AdminAccessException::notFound();
        }

        return $row;
    }

    private function moduleAvailable(int $tenantId, string $moduleKey): bool
    {
        if (in_array($moduleKey, ['core', 'platform'], true)) {
            return true;
        }

        return $this->fetchOne(<<<'SQL'
SELECT tenant_module.id
FROM pa_module_installation installation
JOIN pa_tenant_module tenant_module ON tenant_module.module_key = installation.module_key
WHERE installation.module_key = :module_key AND installation.status = 'active'
  AND tenant_module.tenant_id = :tenant_id AND tenant_module.status = 'enabled'
  AND (tenant_module.effective_at IS NULL OR tenant_module.effective_at <= CURRENT_TIMESTAMP(3))
  AND (tenant_module.expires_at IS NULL OR tenant_module.expires_at > CURRENT_TIMESTAMP(3))
SQL, ['module_key' => $moduleKey, 'tenant_id' => $tenantId]) !== null;
    }

    /** @return array<string, mixed> */
    private function requireRole(int $tenantId, int $roleId, bool $forUpdate): array
    {
        $role = $this->fetchOne(
            "SELECT * FROM pa_role WHERE tenant_id = :tenant_id AND id = :role_id AND status = 'active'"
            . ($forUpdate ? ' FOR UPDATE' : ''),
            ['tenant_id' => $tenantId, 'role_id' => $roleId],
        );
        if ($role === null) {
            throw AdminAccessException::notFound();
        }

        return $role;
    }

    private function tenant(int $tenantId, bool $forUpdate): void
    {
        if ($this->fetchOne(
            "SELECT id FROM pa_tenant WHERE id = :tenant_id AND status = 'active'"
            . ($forUpdate ? ' FOR UPDATE' : ''),
            ['tenant_id' => $tenantId],
        ) === null) {
            throw AdminAccessException::notFound();
        }
    }

    /** @return array<string, mixed> */
    private function policy(int $policyId): array
    {
        $policy = $this->fetchOne(<<<'SQL'
SELECT policy.id, policy.tenant_id, policy.role_id,
       resource.`key` AS resource_key, operation_row.operation,
       policy.status, policy.valid_from, policy.valid_until,
       policy.revision, policy.reason, policy.created_at, policy.updated_at
FROM pa_data_permission_policy policy
JOIN pa_protected_resource resource ON resource.id = policy.protected_resource_id
JOIN pa_resource_operation operation_row ON operation_row.id = policy.resource_operation_id
WHERE policy.id = :policy_id
SQL, ['policy_id' => $policyId]);
        if ($policy === null) {
            throw AdminAccessException::notFound();
        }
        $groupStatement = $this->statement(<<<'SQL'
SELECT id, name, match_mode, sort_order, status, revision
FROM pa_data_permission_group
WHERE tenant_id = :tenant_id AND data_permission_policy_id = :policy_id
ORDER BY sort_order, id
SQL);
        $groupStatement->execute(['tenant_id' => (int) $policy['tenant_id'], 'policy_id' => $policyId]);
        $groups = [];
        while (($group = $groupStatement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $conditionStatement = $this->statement(<<<'SQL'
SELECT condition_row.id, definition.`key` AS condition_key,
       condition_row.target_set_id, condition_row.config_json,
       condition_row.status, condition_row.revision
FROM pa_data_permission_condition condition_row
JOIN pa_data_condition_definition definition ON definition.id = condition_row.condition_definition_id
WHERE condition_row.tenant_id = :tenant_id AND condition_row.data_permission_group_id = :group_id
ORDER BY condition_row.id
SQL);
            $conditionStatement->execute([
                'tenant_id' => (int) $policy['tenant_id'],
                'group_id' => (int) $group['id'],
            ]);
            $conditions = [];
            while (($condition = $conditionStatement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $condition['target_set'] = $condition['target_set_id'] === null
                    ? null
                    : $this->targetSet((int) $policy['tenant_id'], (int) $condition['target_set_id']);
                unset($condition['target_set_id']);
                $condition['config'] = $condition['config_json'] === null
                    ? null
                    : $this->decode((string) $condition['config_json']);
                unset($condition['config_json']);
                $conditions[] = $this->normalize($condition);
            }
            $group['conditions'] = $conditions;
            $groups[] = $this->normalize($group);
        }
        $policy['groups'] = $groups;

        return $this->normalize($policy);
    }

    /** @return array<string, mixed> */
    private function targetSet(int $tenantId, int $targetSetId): array
    {
        $targetSet = $this->fetchOne(<<<'SQL'
SELECT id, name, target_mode, target_resource_key, status, revision
FROM pa_data_permission_target_set
WHERE tenant_id = :tenant_id AND id = :target_set_id
SQL, ['tenant_id' => $tenantId, 'target_set_id' => $targetSetId]);
        if ($targetSet === null) {
            throw new AdminAccessException('DATABASE_DATA_INVALID', 500, 'Policy target set is missing.');
        }
        $statement = $this->statement(<<<'SQL'
SELECT target_id FROM pa_data_permission_target
WHERE tenant_id = :tenant_id AND target_set_id = :target_set_id AND status = 'active'
ORDER BY target_id
SQL);
        $statement->execute(['tenant_id' => $tenantId, 'target_set_id' => $targetSetId]);
        $targetSet['targets'] = array_values(array_map(
            static fn(string $targetId): array => ['target_id' => $targetId],
            array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)),
        ));

        return $this->normalize($targetSet);
    }

    /** @param list<string> $targetKeys */
    private function audit(
        TenantContext $actor,
        int $policyId,
        int $roleId,
        string $resourceKey,
        string $operation,
        array $targetKeys,
        string $now,
    ): void {
        $digest = $targetKeys === [] ? null : hash('sha256', implode('|', $targetKeys));
        $this->execute(<<<'SQL'
INSERT INTO pa_tenant_audit_event (
    tenant_id, event_type, action, outcome,
    actor_tenant_id, actor_tenant_member_id, actor_account_id, actor_type,
    target_resource_type, target_resource_id, target_count, target_set_digest,
    request_id, metadata_json, occurred_at
) VALUES (
    :tenant_id, 'tenant.data-policy.replaced', 'core.role.data-policy.manage', 'success',
    :actor_tenant_id, :member_id, :account_id, 'member',
    'data-policy', :policy_id, :target_count, :target_digest,
    :request_id, :metadata_json, :occurred_at
)
SQL, [
            'tenant_id' => $actor->tenantId,
            'actor_tenant_id' => $actor->tenantId,
            'member_id' => $actor->memberId,
            'account_id' => $actor->accountId,
            'policy_id' => (string) $policyId,
            'target_count' => count($targetKeys),
            'target_digest' => $digest,
            'request_id' => $actor->requestId,
            'metadata_json' => json_encode([
                'role_id' => (string) $roleId,
                'resource_key' => $resourceKey,
                'operation' => $operation,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $now,
        ]);
    }

    /** @param array<string, mixed> $value
     * @param list<string> $allowed
     */
    private function assertKeys(array $value, array $allowed): void
    {
        $unknown = array_diff(array_keys($value), $allowed);
        if ($unknown !== []) {
            throw AdminAccessException::invalid(
                'DATA_POLICY_FIELD_UNKNOWN',
                'The data policy contains an unsupported field.',
            );
        }
    }

    private function requiredText(mixed $value, int $maxLength, string $errorCode): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $maxLength) {
            throw AdminAccessException::invalid($errorCode, 'A required text field is invalid.');
        }

        return trim($value);
    }

    private function optionalText(mixed $value, int $maxLength, string $errorCode): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || mb_strlen(trim($value)) > $maxLength) {
            throw AdminAccessException::invalid($errorCode, 'An optional text field is invalid.');
        }

        return trim($value) === '' ? null : trim($value);
    }

    private function date(mixed $value, string $field): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw AdminAccessException::invalid('DATA_POLICY_PERIOD_INVALID', "{$field} must be an ISO-8601 timestamp.");
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw AdminAccessException::invalid('DATA_POLICY_PERIOD_INVALID', "{$field} must be an ISO-8601 timestamp.");
        }
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AdminAccessException('DATABASE_DATA_INVALID', 500, 'Stored policy configuration is invalid.');
        }

        return is_array($value) ? $value : [];
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

    /** @param array<string, int|string|null> $parameters */
    private function execute(string $sql, array $parameters = []): int
    {
        $statement = $this->statement($sql);
        $statement->execute($parameters);

        return $statement->rowCount();
    }

    /** @param array<string, int|string|null> $parameters
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $sql, array $parameters = []): ?array
    {
        $statement = $this->statement($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function statement(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new AdminAccessException('DATABASE_ERROR', 500, 'Could not prepare the database operation.');
        }

        return $statement;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }

    /** @template T
     * @param callable(): T $operation
     * @return T
     */
    private function transaction(callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }
}
