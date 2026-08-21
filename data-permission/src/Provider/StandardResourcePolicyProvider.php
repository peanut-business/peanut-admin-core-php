<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Provider;

use PeanutAdmin\DataPermission\Catalog\ResourceOperation;
use PeanutAdmin\DataPermission\Constraint\AlwaysFalse;
use PeanutAdmin\DataPermission\Constraint\AlwaysTrue;
use PeanutAdmin\DataPermission\Constraint\AndConstraint;
use PeanutAdmin\DataPermission\Constraint\ColumnEquals;
use PeanutAdmin\DataPermission\Constraint\ColumnIn;
use PeanutAdmin\DataPermission\Constraint\ExistsByContract;
use PeanutAdmin\DataPermission\Constraint\JsonArrayContainsColumn;
use PeanutAdmin\DataPermission\Constraint\OrConstraint;
use PeanutAdmin\DataPermission\Constraint\QueryConstraint;
use PeanutAdmin\DataPermission\Context\AuthorizationContext;
use PeanutAdmin\DataPermission\Decision\AuthorizationDecision;
use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;
use PeanutAdmin\DataPermission\Policy\EffectiveCondition;
use PeanutAdmin\DataPermission\Policy\EffectiveConditionGroup;
use PeanutAdmin\DataPermission\Policy\EffectivePolicySet;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetCollection;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetSet;

final readonly class StandardResourcePolicyProvider implements
    ResourceQueryPolicyProvider,
    ResourceTargetPolicyProvider,
    ResourceCreatePolicyProvider
{
    public function __construct(
        private ProviderColumnMap $columns,
        private DepartmentHierarchyProvider $departments,
        private TargetSetMembershipProvider $targetSets,
        private ConditionProviderRegistry $customConditions = new ConditionProviderRegistry(),
    ) {}

    public function tenantConstraint(
        AuthorizationContext $context,
        ResourceOperation $operation,
    ): QueryConstraint {
        return new \PeanutAdmin\DataPermission\Constraint\TenantEquals(
            $this->columns->tenantColumn,
            $context->tenant->tenantId,
        );
    }

    public function requestedTargetConstraint(
        AuthorizationContext $context,
        ResourceOperation $operation,
        TypedResourceTargetCollection $targets,
    ): QueryConstraint {
        if ($targets->sets === []) {
            return new AlwaysTrue();
        }
        $constraints = [];
        foreach ($targets->sets as $targetSet) {
            $column = $this->columns->targetColumns[$targetSet->targetResourceKey] ?? null;
            if ($column === null || $targetSet->targetIds === []) {
                throw new DataAuthorizationException(
                    'AUTHZ_TARGET_CARDINALITY_INVALID',
                    'Requested target filters must use a registered column and non-empty IDs.',
                );
            }
            $constraints[] = count($targetSet->targetIds) <= 500
                ? new ColumnIn($column, $targetSet->targetIds)
                : new JsonArrayContainsColumn($column, $targetSet->targetIds);
        }

        return count($constraints) === 1 ? $constraints[0] : new AndConstraint($constraints);
    }

    public function compilePredicate(
        AuthorizationContext $context,
        ResourceOperation $operation,
        EffectivePolicySet $policies,
    ): QueryConstraint {
        if ($policies->groups === []) {
            return new AlwaysFalse();
        }

        $groups = [];
        foreach ($policies->groups as $group) {
            $constraints = [];
            foreach ($group->conditions as $condition) {
                $constraints[] = $this->compileCondition($context, $operation, $policies, $condition);
            }
            if (count($constraints) > 1 && $this->containsTenantAll($group)) {
                throw new DataAuthorizationException(
                    'AUTHZ_CONDITION_UNSUPPORTED',
                    'core.tenant_all must be the only condition in its group.',
                );
            }
            $groups[] = count($constraints) === 1
                ? $constraints[0]
                : new AndConstraint($constraints);
        }

        return count($groups) === 1 ? $groups[0] : new OrConstraint($groups);
    }

    public function assertTargetsAllowed(
        AuthorizationContext $context,
        ResourceOperation $operation,
        TypedResourceTargetCollection $targets,
        EffectivePolicySet $policies,
    ): AuthorizationDecision {
        if ($policies->groups === []) {
            return AuthorizationDecision::deny();
        }
        foreach ($policies->groups as $group) {
            if ($this->groupAllowsTargets($context, $group, $targets)) {
                return AuthorizationDecision::allow([$group->policyId]);
            }
        }

        return AuthorizationDecision::deny();
    }

    public function assertCreateAllowed(
        AuthorizationContext $context,
        ResourceOperation $operation,
        TypedResourceTargetCollection $targets,
        EffectivePolicySet $policies,
    ): AuthorizationDecision {
        return $this->assertTargetsAllowed($context, $operation, $targets, $policies);
    }

    private function compileCondition(
        AuthorizationContext $context,
        ResourceOperation $operation,
        EffectivePolicySet $policies,
        EffectiveCondition $condition,
    ): QueryConstraint {
        return match ($condition->key) {
            'core.tenant_all' => new AlwaysTrue(),
            'core.self' => $this->columns->selfColumn === null
                ? new AlwaysFalse()
                : new ColumnEquals($this->columns->selfColumn, $context->tenant->memberId),
            'core.own_department' => $this->departmentEquals($policies->primaryDepartmentId),
            'core.department_tree' => $this->departmentTree($context, $policies->primaryDepartmentId),
            'core.specified_departments' => $this->selectedTargets(
                $context,
                $condition,
                $this->columns->departmentColumn,
                'core.department',
            ),
            'core.specified_objects' => $this->selectedTargets(
                $context,
                $condition,
                $condition->targetResourceKey === null
                    ? null
                    : ($this->columns->targetColumns[$condition->targetResourceKey] ?? null),
                $condition->targetResourceKey,
            ),
            default => $this->customConditions->get($condition->key)->compile(
                $context,
                $operation,
                $condition,
                $this->columns,
            ),
        };
    }

    private function departmentEquals(?int $departmentId): QueryConstraint
    {
        if ($departmentId === null || $this->columns->departmentColumn === null) {
            return new AlwaysFalse();
        }

        return new ColumnEquals($this->columns->departmentColumn, $departmentId);
    }

    private function departmentTree(
        AuthorizationContext $context,
        ?int $departmentId,
    ): QueryConstraint {
        if ($departmentId === null || $this->columns->departmentColumn === null) {
            return new AlwaysFalse();
        }
        $departmentIds = $this->departments->descendantsIncludingSelf(
            $context->tenant->tenantId,
            $departmentId,
        );
        if ($departmentIds === []) {
            return new AlwaysFalse();
        }

        return new ColumnIn($this->columns->departmentColumn, $departmentIds);
    }

    private function selectedTargets(
        AuthorizationContext $context,
        EffectiveCondition $condition,
        ?\PeanutAdmin\DataPermission\Constraint\ColumnReference $column,
        ?string $requiredResourceKey,
    ): QueryConstraint {
        if (
            $column === null
            || $condition->targetSetId === null
            || $condition->targetResourceKey !== $requiredResourceKey
            || $condition->targetCount === 0
        ) {
            return new AlwaysFalse();
        }
        if ($condition->targetCount > 500) {
            return new ExistsByContract(
                'data_permission.target-set',
                $column,
                $context->tenant->tenantId,
                $condition->targetSetId,
            );
        }
        if ($condition->targetIds === []) {
            return new AlwaysFalse();
        }

        return new ColumnIn($column, $condition->targetIds);
    }

    private function containsTenantAll(EffectiveConditionGroup $group): bool
    {
        foreach ($group->conditions as $condition) {
            if ($condition->key === 'core.tenant_all') {
                return true;
            }
        }

        return false;
    }

    private function groupAllowsTargets(
        AuthorizationContext $context,
        EffectiveConditionGroup $group,
        TypedResourceTargetCollection $targets,
    ): bool {
        $uncovered = [];
        foreach ($targets->sets as $targetSet) {
            $uncovered[$targetSet->targetRole . ':' . $targetSet->targetResourceKey] = true;
        }
        foreach ($group->conditions as $condition) {
            if ($condition->key === 'core.tenant_all') {
                return count($group->conditions) === 1;
            }
            if (!in_array($condition->key, ['core.specified_departments', 'core.specified_objects'], true)) {
                return false;
            }
            if (!$this->conditionContainsTargets($context, $condition, $targets)) {
                return false;
            }
            foreach ($targets->sets as $targetSet) {
                if ($targetSet->targetResourceKey === $condition->targetResourceKey) {
                    unset($uncovered[$targetSet->targetRole . ':' . $targetSet->targetResourceKey]);
                }
            }
        }

        return $uncovered === [];
    }

    private function conditionContainsTargets(
        AuthorizationContext $context,
        EffectiveCondition $condition,
        TypedResourceTargetCollection $targets,
    ): bool {
        if ($condition->targetSetId === null || $condition->targetResourceKey === null) {
            return false;
        }
        $matched = false;
        foreach ($targets->sets as $targetSet) {
            if ($targetSet->targetResourceKey !== $condition->targetResourceKey) {
                continue;
            }
            $matched = true;
            if (!$this->targetSetContains($context, $condition, $targetSet)) {
                return false;
            }
        }

        return $matched;
    }

    private function targetSetContains(
        AuthorizationContext $context,
        EffectiveCondition $condition,
        TypedResourceTargetSet $targets,
    ): bool {
        if ($condition->targetCount > 500) {
            return $this->targetSets->containsAll(
                $context->tenant->tenantId,
                (int) $condition->targetSetId,
                $targets->targetIds,
            );
        }

        return array_diff($targets->targetIds, $condition->targetIds) === [];
    }
}
