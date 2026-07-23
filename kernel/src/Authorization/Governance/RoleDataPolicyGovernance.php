<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Governance;

final class RoleDataPolicyGovernance
{
    /** @var array<string, GovernanceResourceOperation> */
    private array $operations = [];

    /** @param list<GovernanceResourceOperation> $operations */
    public function __construct(array $operations)
    {
        foreach ($operations as $operation) {
            $key = $operation->resourceKey . ':' . $operation->operation;
            if (isset($this->operations[$key])) {
                throw new GovernanceException('GOVERNANCE_OPERATION_CONFLICT', 'A resource operation is declared more than once.');
            }
            $this->operations[$key] = $operation;
        }
    }

    /**
     * @param list<string> $conditionKeys
     * @param list<string> $availableModules
     */
    public function prepare(
        string $audience,
        int $roleId,
        int $currentRevision,
        string $ifMatch,
        string $resourceKey,
        string $operation,
        array $conditionKeys,
        array $availableModules,
    ): RoleDataPolicyChange {
        if ($audience !== 'tenant' || $roleId < 1) {
            throw new GovernanceException(
                'GOVERNANCE_DATA_POLICY_AUDIENCE_MISMATCH',
                'Role data-policy governance is available only to the Tenant audience.',
            );
        }
        $declared = $this->operations[$resourceKey . ':' . $operation] ?? throw new GovernanceException(
            'GOVERNANCE_OPERATION_UNDECLARED',
            'The requested resource operation is not declared.',
        );
        if ($declared->audience !== $audience) {
            throw new GovernanceException('GOVERNANCE_OPERATION_AUDIENCE_MISMATCH', 'The resource operation belongs to another audience.');
        }
        if (!in_array($declared->moduleKey, ['core', 'platform'], true)
            && !in_array($declared->moduleKey, $availableModules, true)) {
            throw new GovernanceException('GOVERNANCE_OPERATION_MODULE_UNAVAILABLE', 'The resource operation Module is unavailable.');
        }
        $conditions = array_values(array_unique($conditionKeys));
        foreach ($conditions as $condition) {
            if (!is_string($condition) || !in_array($condition, $declared->conditionKeys, true)) {
                throw new GovernanceException('GOVERNANCE_CONDITION_UNDECLARED', 'A data-policy condition is not declared for this operation.');
            }
        }
        sort($conditions, SORT_STRING);

        return new RoleDataPolicyChange(
            $roleId,
            RevisionPrecondition::require($ifMatch, $currentRevision),
            $resourceKey,
            $operation,
            $conditions,
        );
    }
}
