<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Host;

use PeanutAdmin\Kernel\Tenancy\TenantEntryBindingResolver;

final readonly class ApplicationHostPolicy
{
    /**
     * @param list<string> $platformHosts
     * @param list<string> $tenantAdminHosts
     */
    public function __construct(
        private string $deploymentMode,
        private array $platformHosts,
        private array $tenantAdminHosts,
        private TenantEntryBindingResolver $bindings,
    ) {}

    public function assertPlatform(object $request): void
    {
        if ($this->deploymentMode !== 'multi-tenant') {
            throw new \DomainException('PLATFORM_HOST_UNAVAILABLE');
        }
        if (!in_array(self::requestHost($request), $this->platformHosts, true)) {
            throw new \DomainException('PLATFORM_HOST_FORBIDDEN');
        }
    }

    public function assertTenantAdmin(object $request): void
    {
        if ($this->deploymentMode === 'standalone') {
            return;
        }
        if ($this->deploymentMode !== 'multi-tenant') {
            throw new \DomainException('TENANT_ADMIN_HOST_UNAVAILABLE');
        }
        $host = self::requestHost($request);
        if (in_array($host, $this->platformHosts, true)) {
            throw new \DomainException('TENANT_ADMIN_HOST_FORBIDDEN');
        }
        if (in_array($host, $this->tenantAdminHosts, true)) {
            return;
        }
        if ($this->bindings->boundTenantId($request, TenantEntryBindingResolver::ADMIN_CLIENT) === null) {
            throw new \DomainException('TENANT_ADMIN_HOST_FORBIDDEN');
        }
    }

    /** @return list<string> */
    public static function hostList(string $value): array
    {
        $hosts = [];
        foreach (explode(',', $value) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                $hosts[] = TenantEntryBindingResolver::normalizeHost($candidate);
            }
        }
        return array_values(array_unique($hosts));
    }

    private static function requestHost(object $request): string
    {
        if (!method_exists($request, 'host')) {
            throw new \DomainException('TENANT_ENTRY_HOST_INVALID');
        }
        return TenantEntryBindingResolver::normalizeHost((string) $request->host());
    }
}
