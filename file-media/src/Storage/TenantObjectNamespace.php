<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Storage;

final class TenantObjectNamespace
{
    public static function directory(int $tenantId, string $relativeDirectory): string
    {
        self::assertTenantId($tenantId);
        $relativeDirectory = trim($relativeDirectory, '/');
        if ($relativeDirectory === ''
            || str_contains($relativeDirectory, '..')
            || preg_match('#^[A-Za-z0-9][A-Za-z0-9/_-]*$#D', $relativeDirectory) !== 1) {
            throw new \InvalidArgumentException('The object directory is invalid.');
        }

        return sprintf('tenants/v1/%d/%s', $tenantId, $relativeDirectory);
    }

    public static function ownsUri(int $tenantId, string $uri): bool
    {
        self::assertTenantId($tenantId);
        $relative = ltrim($uri, '/');
        if (str_starts_with($relative, 'storage/')) {
            $relative = substr($relative, strlen('storage/'));
        }

        return str_starts_with($relative, sprintf('tenants/v1/%d/', $tenantId));
    }

    public static function assertOwnedUri(int $tenantId, string $uri): void
    {
        if ($uri === '' || !self::ownsUri($tenantId, $uri)) {
            throw new \RuntimeException('The object does not belong to the current Tenant.');
        }
    }

    private static function assertTenantId(int $tenantId): void
    {
        if ($tenantId < 1) {
            throw new \InvalidArgumentException('The Tenant ID is invalid.');
        }
    }
}
