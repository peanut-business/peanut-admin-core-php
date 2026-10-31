<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization;

use PeanutAdmin\Kernel\Auth\TenantContext;

interface TenantAuthorizationRepository
{
    /**
     * @return array{id: int, display_name: string|null, status: string, primary_department_id: int|null}|null
     */
    public function member(int $tenantId, int $memberId): ?array;

    /** @return list<array{id: int, key: string, name: string, is_builtin: bool}> */
    public function activeRoles(int $tenantId, int $memberId): array;

    public function revision(int $tenantId, int $memberId): string;

    public function permissions(int $tenantId, int $memberId): EffectivePermissionSet;

    /** Live grants for only the requested keys; Core owner constants retain their native semantics.
     * @param list<string> $permissionKeys
     */
    public function permissionsForKeys(TenantContext $context, array $permissionKeys): EffectivePermissionSet;
}
