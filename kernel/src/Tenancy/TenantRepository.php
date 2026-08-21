<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tenancy;

interface TenantRepository
{
    public function createProvisioning(string $code, string $name): TenantRecord;

    public function byId(int $tenantId, bool $forUpdate = false): ?TenantRecord;

    public function byCode(string $code, bool $forUpdate = false): ?TenantRecord;

    public function transition(int $tenantId, TenantStatus $next): TenantRecord;
}
