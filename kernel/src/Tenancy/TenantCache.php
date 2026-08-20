<?php
declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tenancy;

/** Tenant-only cache facade; every operation is scoped at construction time. */
final class TenantCache
{
    public function __construct(
        private readonly TenantScope $scope,
        private readonly TenantCacheStore $store
    ) {
    }

    public function get(string $logicalKey, mixed $default = null): mixed
    {
        return $this->store->get(
            TenantNamespace::cacheKey($this->scope, $logicalKey),
            $default
        );
    }

    public function set(string $logicalKey, mixed $value, int $ttlSeconds = 0): bool
    {
        if ($ttlSeconds < 0) {
            throw new \InvalidArgumentException('Tenant cache TTL cannot be negative');
        }

        return $this->store->set(
            TenantNamespace::cacheKey($this->scope, $logicalKey),
            $value,
            $ttlSeconds
        );
    }

    public function delete(string $logicalKey): bool
    {
        return $this->store->delete(
            TenantNamespace::cacheKey($this->scope, $logicalKey)
        );
    }
}
