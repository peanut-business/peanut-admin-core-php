<?php
declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tenancy;

use PeanutAdmin\Kernel\Auth\TenantContext;

final readonly class TenantAvailabilityGuard
{
    public function __construct(private TenantRepository $tenants) {}

    public function assertNewSessionAllowed(int $tenantId): void
    {
        $this->assertActive($tenantId);
    }

    public function assertBusinessWriteAllowed(TenantContext $context): void
    {
        if ($context->tenantId <= 0 || $context->sessionKey === '' || $context->requestId === '') {
            throw new \DomainException('TRUSTED_TENANT_CONTEXT_REQUIRED');
        }
        $this->assertActive($context->tenantId);
    }

    private function assertActive(int $tenantId): void
    {
        if ($tenantId <= 0) throw new \DomainException('TRUSTED_TENANT_CONTEXT_REQUIRED');
        $tenant = $this->tenants->byId($tenantId);
        if ($tenant === null || $tenant->status !== TenantStatus::Active) {
            throw new \DomainException('TENANT_UNAVAILABLE');
        }
    }
}
