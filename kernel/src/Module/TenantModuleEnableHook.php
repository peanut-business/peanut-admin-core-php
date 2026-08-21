<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

interface TenantModuleEnableHook
{
    /** @param array<string, mixed> $config */
    public function enable(int $tenantId, array $config): void;

    public function disable(int $tenantId): void;
}
