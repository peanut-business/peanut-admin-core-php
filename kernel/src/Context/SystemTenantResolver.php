<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Context;

interface SystemTenantResolver
{
    public function activeTenantIdByCode(string $tenantCode): ?int;
}
