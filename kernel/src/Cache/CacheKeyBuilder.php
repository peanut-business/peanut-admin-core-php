<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Cache;

use InvalidArgumentException;

final class CacheKeyBuilder
{
    private function __construct() {}

    public static function tenant(
        int $tenantId,
        string $resource,
        int $revision,
        string $key,
    ): string {
        if ($tenantId <= 0 || $revision <= 0) {
            throw new InvalidArgumentException('Tenant and revision must be positive.');
        }

        return sprintf(
            'pa:tenant:%d:%s:r%d:%s',
            $tenantId,
            self::segment($resource),
            $revision,
            self::segment($key),
        );
    }

    public static function platform(string $resource, int $revision, string $key): string
    {
        if ($revision <= 0) {
            throw new InvalidArgumentException('Revision must be positive.');
        }

        return sprintf(
            'pa:platform:%s:r%d:%s',
            self::segment($resource),
            $revision,
            self::segment($key),
        );
    }

    private static function segment(string $value): string
    {
        if (preg_match('/^[a-zA-Z0-9._-]+$/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid cache key segment.');
        }

        return $value;
    }
}
