<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

use PeanutAdmin\Kernel\Auth\TenantContext;

interface ModuleRuntimeRepository
{
    public function installation(string $moduleKey): ?ModuleInstallationRecord;

    public function tenantModule(int $tenantId, string $moduleKey, ?TenantContext $context = null): ?TenantModuleRecord;
}
