<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

/**
 * Tenant lifecycle command callbacks run before the repository write, inside the caller's transaction.
 * Implementations must limit work to rollbackable database changes and let failures propagate.
 */
interface TenantModuleEnableHook
{
    /** @param array<string, mixed> $config */
    public function enable(int $tenantId, array $config): void;

    public function disable(int $tenantId): void;
}
