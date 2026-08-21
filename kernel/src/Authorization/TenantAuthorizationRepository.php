<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization;

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
}
