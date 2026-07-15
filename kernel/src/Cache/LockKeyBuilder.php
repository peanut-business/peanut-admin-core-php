<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Cache;

final class LockKeyBuilder
{
    private function __construct() {}

    public static function tenant(int $tenantId, string $resource, int $revision, string $key): string
    {
        return 'lock:' . CacheKeyBuilder::tenant($tenantId, $resource, $revision, $key);
    }

    public static function platform(string $resource, int $revision, string $key): string
    {
        return 'lock:' . CacheKeyBuilder::platform($resource, $revision, $key);
    }
}
