<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Governance;

final class GovernancePermissionCatalog
{
    /** @var array<string, GovernancePermission> */
    private array $permissions = [];

    /** @param list<GovernancePermission> $permissions */
    public function __construct(array $permissions)
    {
        foreach ($permissions as $permission) {
            if (isset($this->permissions[$permission->key])) {
                throw new GovernanceException(
                    'GOVERNANCE_PERMISSION_CONFLICT',
                    'A governance Permission key is declared more than once.',
                );
            }
            $this->permissions[$permission->key] = $permission;
        }
    }

    public function require(string $key, string $audience): GovernancePermission
    {
        $permission = $this->permissions[$key] ?? throw new GovernanceException(
            'GOVERNANCE_PERMISSION_UNDECLARED',
            'The requested Permission is not declared by the trusted catalog.',
        );
        if ($permission->audience !== $audience) {
            throw new GovernanceException(
                'GOVERNANCE_PERMISSION_AUDIENCE_MISMATCH',
                'The requested Permission belongs to another audience.',
            );
        }
        if (!$permission->active) {
            throw new GovernanceException(
                'GOVERNANCE_PERMISSION_INACTIVE',
                'The requested Permission is inactive.',
            );
        }

        return $permission;
    }

    /**
     * @param list<string> $keys
     * @param list<string> $availableModules
     * @return list<string>
     */
    public function assignment(string $audience, array $keys, array $availableModules): array
    {
        $resolved = [];
        foreach (array_values(array_unique($keys)) as $key) {
            if ($key === '*' || $key === '') {
                throw new GovernanceException(
                    'GOVERNANCE_PERMISSION_INVALID',
                    'A role Permission key is invalid.',
                );
            }
            $permission = $this->require($key, $audience);
            if (!in_array($permission->moduleKey, ['core', 'platform'], true)
                && !in_array($permission->moduleKey, $availableModules, true)) {
                throw new GovernanceException(
                    'GOVERNANCE_PERMISSION_MODULE_UNAVAILABLE',
                    'A role Permission belongs to an unavailable Module.',
                );
            }
            $resolved[] = $permission->key;
        }
        sort($resolved, SORT_STRING);

        return $resolved;
    }
}
