<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Application;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PeanutAdmin\DataPermission\Model\DataPermissionConditionRecord;
use PeanutAdmin\DataPermission\Model\DataPermissionGroupRecord;
use PeanutAdmin\DataPermission\Model\DataPermissionPolicyRecord;
use PeanutAdmin\DataPermission\Model\DataPermissionTargetRecord;
use PeanutAdmin\DataPermission\Model\DataPermissionTargetSetRecord;
use PeanutAdmin\DataPermission\Model\ProtectedResourceRecord;
use PeanutAdmin\DataPermission\Model\ResourceOperationConditionRecord;
use PeanutAdmin\DataPermission\Model\TargetTypeRecord;
use PeanutAdmin\DataPermission\Target\TargetResolverRegistry;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetSet;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Audit\AuditService;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Module\Model\ModuleInstallation;
use PeanutAdmin\Kernel\Persistence\Model\Department;
use PeanutAdmin\Kernel\Persistence\Model\Role;
use PeanutAdmin\Kernel\Persistence\Model\Tenant;
use think\facade\Db;
use Throwable;

final readonly class DataPolicyAdminService
{
    public function __construct(
        private TargetResolverRegistry $targetResolvers,
        private AuditService $audit,
    ) {}

    /** @return array<string, mixed> */
    public function get(int $tenantId, int $roleId, string $resourceKey, string $operation): array
    {
        $catalog = $this->operation($tenantId, $resourceKey, $operation);
        $this->requireRole($tenantId, $roleId, false);
        $policyId = DataPermissionPolicyRecord::where('tenant_id', $tenantId)
            ->where('role_id', $roleId)
            ->where('resource_operation_id', (int) $catalog['operation_id'])
            ->value('id');
        if ($policyId === null) {
            throw AdminAccessException::notFound();
        }

        return $this->policy((int) $policyId);
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

        return Db::transaction(function () use (
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
            $existing = DataPermissionPolicyRecord::where('tenant_id', $actor->tenantId)
                ->where('role_id', $roleId)
                ->where('resource_operation_id', (int) $catalog['operation_id'])
                ->lock(true)
                ->find();
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
                $policyId = (int) DataPermissionPolicyRecord::insertGetId([
                    'tenant_id' => $actor->tenantId,
                    'role_id' => $roleId,
                    'protected_resource_id' => (int) $catalog['resource_id'],
                    'resource_operation_id' => (int) $catalog['operation_id'],
                    'status' => $input['status'],
                    'valid_from' => $input['valid_from'],
                    'valid_until' => $input['valid_until'],
                    'revision' => 1,
                    'reason' => $input['reason'],
                    'created_by_member_id' => $actor->memberId,
                    'updated_by_member_id' => $actor->memberId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $policyId = (int) $existing['id'];
                $this->deletePolicyChildren($actor->tenantId, $policyId);
                if (DataPermissionPolicyRecord::where('tenant_id', $actor->tenantId)
                    ->where('id', $policyId)
                    ->where('revision', $expectedRevision)
                    ->update([
                    'status' => $input['status'],
                    'valid_from' => $input['valid_from'],
                    'valid_until' => $input['valid_until'],
                    'reason' => $input['reason'],
                    'revision' => Db::raw('revision + 1'),
                    'updated_by_member_id' => $actor->memberId,
                    'updated_at' => $now,
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
            Role::where('tenant_id', $actor->tenantId)
                ->where('id', $roleId)
                ->where('authorization_revision', (int) $role['authorization_revision'])
                ->update([
                'authorization_revision' => Db::raw('authorization_revision + 1'),
                'updated_at' => $now,
            ]);
            Tenant::where('id', $actor->tenantId)->update([
                'authorization_revision' => Db::raw('authorization_revision + 1'),
                'updated_at' => $now,
            ]);
            $this->audit(
                $actor,
                $policyId,
                $roleId,
                $resourceKey,
                $operation,
                $targetAuditKeys,
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
            $targetType = TargetTypeRecord::where('key', $targetResourceKey)
                ->where('status', 'active')
                ->field('resolver_key,module_key')
                ->find();
            if (!is_array($targetType) || !$this->moduleAvailable($actor->tenantId, (string) $targetType['module_key'])) {
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
        $count = Department::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereIn('id', $targetIds)
            ->count();
        if ((int) $count !== count($targetIds)) {
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
            $groupId = (int) DataPermissionGroupRecord::insertGetId([
                'tenant_id' => $tenantId,
                'data_permission_policy_id' => $policyId,
                'name' => $group['name'],
                'match_mode' => 'all',
                'sort_order' => $sortOrder,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($group['conditions'] as $condition) {
                $targetSetId = null;
                if ($condition['target_set'] !== null) {
                    $targetSet = $condition['target_set'];
                    $targetSetId = (int) DataPermissionTargetSetRecord::insertGetId([
                        'tenant_id' => $tenantId,
                        'name' => $targetSet['name'],
                        'target_mode' => $condition['target_mode'],
                        'target_resource_key' => $targetSet['target_resource_key'],
                        'status' => 'active',
                        'created_by_member_id' => $memberId,
                        'updated_by_member_id' => $memberId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    foreach ($targetSet['target_ids'] as $targetId) {
                        DataPermissionTargetRecord::insert([
                            'tenant_id' => $tenantId,
                            'target_set_id' => $targetSetId,
                            'target_id' => $targetId,
                            'status' => 'active',
                            'added_by_member_id' => $memberId,
                            'added_at' => $now,
                        ]);
                        $auditKeys[] = $targetSet['target_resource_key'] . ':' . $targetId;
                    }
                }
                DataPermissionConditionRecord::insert([
                    'tenant_id' => $tenantId,
                    'data_permission_group_id' => $groupId,
                    'condition_definition_id' => $condition['definition_id'],
                    'target_set_id' => $targetSetId,
                    'config_json' => null,
                    'status' => 'active',
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
        $targetSetIds = array_values(array_map('intval', DataPermissionGroupRecord::alias('group_row')
            ->join(
                'data_permission_condition condition_row',
                'condition_row.tenant_id = group_row.tenant_id AND condition_row.data_permission_group_id = group_row.id',
            )
            ->where('group_row.tenant_id', $tenantId)
            ->where('group_row.data_permission_policy_id', $policyId)
            ->whereNotNull('condition_row.target_set_id')
            ->distinct(true)
            ->column('condition_row.target_set_id')));
        if ($targetSetIds !== []) {
            DataPermissionTargetRecord::where('tenant_id', $tenantId)
                ->whereIn('target_set_id', $targetSetIds)
                ->delete();
        }
        $groupIds = $this->groupIds($tenantId, $policyId);
        if ($groupIds !== []) {
            DataPermissionConditionRecord::where('tenant_id', $tenantId)
                ->whereIn('data_permission_group_id', $groupIds)
                ->delete();
        }
        if ($targetSetIds !== []) {
            DataPermissionTargetSetRecord::where('tenant_id', $tenantId)
                ->whereIn('id', $targetSetIds)
                ->delete();
        }
        DataPermissionGroupRecord::where('tenant_id', $tenantId)
            ->where('data_permission_policy_id', $policyId)
            ->delete();
    }

    /** @return list<int> */
    private function groupIds(int $tenantId, int $policyId): array
    {
        return array_values(array_map('intval', DataPermissionGroupRecord::where('tenant_id', $tenantId)
            ->where('data_permission_policy_id', $policyId)
            ->order('id')
            ->column('id')));
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function allowedConditions(int $operationId): array
    {
        $rows = ResourceOperationConditionRecord::alias('allowed')
            ->join('data_condition_definition definition', "definition.id = allowed.condition_definition_id AND definition.status = 'active'")
            ->where('allowed.resource_operation_id', $operationId)
            ->where('allowed.status', 'active')
            ->field([
                'definition.id' => 'definition_id', 'definition.key' => 'condition_key',
                'definition.target_mode', 'definition.config_schema_json', 'allowed.selector_resource_key',
            ])
            ->order('definition.key')
            ->order('allowed.selector_resource_key')
            ->select()
            ->toArray();
        $conditions = [];
        foreach ($rows as $row) {
            $conditions[(string) $row['condition_key']][] = $row;
        }

        return $conditions;
    }

    /** @return array<string, mixed> */
    private function operation(int $tenantId, string $resourceKey, string $operation): array
    {
        $row = ProtectedResourceRecord::alias('resource')
            ->join(
                'resource_operation operation_row',
                "operation_row.protected_resource_id = resource.id AND operation_row.status = 'active'",
            )
            ->where('resource.key', $resourceKey)
            ->where('resource.status', 'active')
            ->where('operation_row.operation', $operation)
            ->field([
                'resource.id' => 'resource_id',
                'resource.module_key',
                'operation_row.id' => 'operation_id',
                'operation_row.target_cardinality',
            ])
            ->find();
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

        return ModuleInstallation::alias('installation')
            ->join('tenant_module tenant_module', 'tenant_module.module_key = installation.module_key')
            ->where('installation.module_key', $moduleKey)
            ->where('installation.status', 'active')
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
            ->value('tenant_module.id') !== null;
    }

    /** @return array<string, mixed> */
    private function requireRole(int $tenantId, int $roleId, bool $forUpdate): array
    {
        $query = Role::where('tenant_id', $tenantId)
            ->where('id', $roleId)
            ->where('status', 'active');
        if ($forUpdate) {
            $query->lock(true);
        }
        $role = $query->find();
        if ($role === null) {
            throw AdminAccessException::notFound();
        }

        return $role;
    }

    private function tenant(int $tenantId, bool $forUpdate): void
    {
        $query = Tenant::where('id', $tenantId)->where('status', 'active');
        if ($forUpdate) {
            $query->lock(true);
        }
        if ($query->value('id') === null) {
            throw AdminAccessException::notFound();
        }
    }

    /** @return array<string, mixed> */
    private function policy(int $policyId): array
    {
        $policy = DataPermissionPolicyRecord::alias('policy')
            ->join('protected_resource resource', 'resource.id = policy.protected_resource_id')
            ->join('resource_operation operation_row', 'operation_row.id = policy.resource_operation_id')
            ->where('policy.id', $policyId)
            ->field([
                'policy.id', 'policy.tenant_id', 'policy.role_id', 'resource.key' => 'resource_key',
                'operation_row.operation', 'policy.status', 'policy.valid_from', 'policy.valid_until',
                'policy.revision', 'policy.reason', 'policy.created_at', 'policy.updated_at',
            ])
            ->find();
        if ($policy === null) {
            throw AdminAccessException::notFound();
        }
        $groupRows = DataPermissionGroupRecord::where('tenant_id', (int) $policy['tenant_id'])
            ->where('data_permission_policy_id', $policyId)
            ->field('id,name,match_mode,sort_order,status,revision')
            ->order('sort_order')
            ->order('id')
            ->select()
            ->toArray();
        $groups = [];
        foreach ($groupRows as $group) {
            $conditionRows = DataPermissionConditionRecord::alias('condition_row')
                ->join(
                    'data_condition_definition definition',
                    'definition.id = condition_row.condition_definition_id',
                )
                ->where('condition_row.tenant_id', (int) $policy['tenant_id'])
                ->where('condition_row.data_permission_group_id', (int) $group['id'])
                ->field([
                    'condition_row.id', 'definition.key' => 'condition_key',
                    'condition_row.target_set_id', 'condition_row.config_json',
                    'condition_row.status', 'condition_row.revision',
                ])
                ->order('condition_row.id')
                ->select()
                ->toArray();
            $conditions = [];
            foreach ($conditionRows as $condition) {
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
        $targetSet = DataPermissionTargetSetRecord::where('tenant_id', $tenantId)
            ->where('id', $targetSetId)
            ->field('id,name,target_mode,target_resource_key,status,revision')
            ->find();
        if ($targetSet === null) {
            throw new AdminAccessException('DATABASE_DATA_INVALID', 500, 'Policy target set is missing.');
        }
        $targetSet['targets'] = array_map(
            static fn(mixed $targetId): array => ['target_id' => (string) $targetId],
            DataPermissionTargetRecord::where('tenant_id', $tenantId)
                ->where('target_set_id', $targetSetId)
                ->where('status', 'active')
                ->order('target_id')
                ->column('target_id'),
        );

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
    ): void {
        $digest = $targetKeys === [] ? null : hash('sha256', implode('|', $targetKeys));
        $this->audit->tenantMember(
            context: $actor,
            eventType: 'tenant.data-policy.replaced',
            action: 'core.role.data-policy.manage',
            targetResourceType: 'data-policy',
            targetResourceId: (string) $policyId,
            metadata: [
                'role_id' => (string) $roleId,
                'resource_key' => $resourceKey,
                'operation' => $operation,
            ],
            targetCount: count($targetKeys),
            targetSetDigest: $digest,
        );
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

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }

}
