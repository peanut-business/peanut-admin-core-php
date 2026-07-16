<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization;

interface TenantAuthorizationRepository
{
    public function revision(int $tenantId, int $memberId): string;

    public function permissions(int $tenantId, int $memberId): EffectivePermissionSet;
}
