<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

interface ModuleRuntimeRepository
{
    public function installation(string $moduleKey): ?ModuleInstallationRecord;

    public function tenantModule(int $tenantId, string $moduleKey): ?TenantModuleRecord;
}
