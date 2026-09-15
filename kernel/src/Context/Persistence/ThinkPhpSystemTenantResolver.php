<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Context\Persistence;

use PeanutAdmin\Kernel\Context\SystemTenantResolver;
use PeanutAdmin\Kernel\Persistence\Model\Tenant;

final readonly class ThinkPhpSystemTenantResolver implements SystemTenantResolver
{
    public function activeTenantIdByCode(string $tenantCode): ?int
    {
        $tenantId = Tenant::where('code', $tenantCode)->where('status', 'active')->value('id');

        return $tenantId === null ? null : (int) $tenantId;
    }
}
