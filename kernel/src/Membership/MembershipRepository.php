<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Membership;

interface MembershipRepository
{
    public function byId(int $tenantId, int $memberId, bool $forUpdate = false): ?TenantMemberRecord;

    public function byTenantAndAccount(
        int $tenantId,
        int $accountId,
        bool $forUpdate = false,
    ): ?TenantMemberRecord;

    public function createPending(int $tenantId, int $accountId, string $displayName): TenantMemberRecord;

    public function transition(
        int $tenantId,
        int $memberId,
        TenantMemberStatus $next,
    ): TenantMemberRecord;

    public function createBuiltinRole(int $tenantId, string $key, string $name): TenantRoleRecord;

    public function roleByKey(int $tenantId, string $key, bool $forUpdate = false): ?TenantRoleRecord;

    public function assignRole(int $tenantId, int $memberId, int $roleId): void;

    public function memberHasRole(int $tenantId, int $memberId, string $roleKey): bool;

    public function activeMemberWithRoleExists(int $tenantId, string $roleKey): bool;

    public function pendingOrActiveMemberWithRoleExists(int $tenantId, string $roleKey): bool;
}
