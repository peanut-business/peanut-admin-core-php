<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tenancy;

/** Minimal store port: intentionally excludes clear, key enumeration and raw store access. */
interface TenantCacheStore
{
    public function get(string $physicalKey, mixed $default = null): mixed;

    public function set(string $physicalKey, mixed $value, int $ttlSeconds = 0): bool;

    public function delete(string $physicalKey): bool;
}
