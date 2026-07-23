<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Governance;

final readonly class RolePermissionGovernance
{
    public function __construct(private GovernancePermissionCatalog $permissions) {}

    /**
     * @param list<string> $permissionKeys
     * @param list<string> $availableModules
     */
    public function prepare(
        string $audience,
        int $roleId,
        int $currentRevision,
        string $ifMatch,
        array $permissionKeys,
        array $availableModules,
    ): RolePermissionChange {
        if (!in_array($audience, ['tenant', 'platform'], true) || $roleId < 1 || $currentRevision < 1) {
            throw new GovernanceException('GOVERNANCE_ROLE_INVALID', 'The governed role identity is invalid.');
        }
        $revision = RevisionPrecondition::require($ifMatch, $currentRevision);

        return new RolePermissionChange(
            $audience,
            $roleId,
            $revision,
            $this->permissions->assignment($audience, $permissionKeys, $availableModules),
        );
    }
}
