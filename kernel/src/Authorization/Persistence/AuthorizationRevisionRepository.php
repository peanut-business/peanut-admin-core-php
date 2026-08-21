<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Persistence;

interface AuthorizationRevisionRepository
{
    public function bumpTenant(int $tenantId): int;

    public function bumpMember(int $tenantId, int $memberId): int;

    public function bumpRole(int $tenantId, int $roleId): int;

    public function bumpTenantModule(int $tenantId, string $moduleKey): int;
}
