<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

use DateTimeImmutable;

interface TenantModuleMutationRepository extends ModuleRuntimeRepository
{
    public function tenantIsActive(int $tenantId): bool;

    /** @param array<string, mixed> $config */
    public function enable(
        int $tenantId,
        string $moduleKey,
        array $config,
        DateTimeImmutable $now,
        string $source = 'manual',
        ?DateTimeImmutable $effectiveAt = null,
        ?DateTimeImmutable $expiresAt = null,
    ): TenantModuleRecord;

    public function disable(int $tenantId, string $moduleKey, DateTimeImmutable $now): TenantModuleRecord;
}
