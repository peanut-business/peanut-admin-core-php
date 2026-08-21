<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tenancy;

/** Builds stable, versioned tenant resource names without exposing raw logical keys. */
final class TenantNamespace
{
    private const MAX_LOGICAL_NAME_BYTES = 512;
    private const PREFIX = 'pa:tn:v1';

    public static function cacheKey(TenantScope $scope, string $logicalKey): string
    {
        return self::PREFIX . ':t=' . $scope->tenantId() . ':cache:k=' . self::digest(
            'cache',
            $scope->tenantId(),
            self::validateLogicalName($logicalKey),
        );
    }

    public static function cacheTag(TenantScope $scope, string $logicalTag): string
    {
        return self::PREFIX . ':t=' . $scope->tenantId() . ':cache:tag=' . self::digest(
            'tag',
            $scope->tenantId(),
            self::validateLogicalName($logicalTag),
        );
    }

    public static function lockName(TenantScope $scope, string $logicalSeed): string
    {
        // 8-byte prefix + 1 separator + 43-byte base64url SHA-256 stays below MySQL's 64-byte limit.
        return self::PREFIX . ':l=' . self::digest(
            'lock',
            $scope->tenantId(),
            self::validateLogicalName($logicalSeed),
        );
    }

    private static function validateLogicalName(string $logicalName): string
    {
        if (trim($logicalName) === ''
            || strlen($logicalName) > self::MAX_LOGICAL_NAME_BYTES
            || preg_match('/[\x00-\x1F\x7F]/', $logicalName) === 1) {
            throw new \InvalidArgumentException('Tenant resource logical name is invalid');
        }
        return $logicalName;
    }

    private static function digest(string $kind, int $tenantId, string $logicalName): string
    {
        $material = self::lengthPrefixed($kind)
            . self::lengthPrefixed((string) $tenantId)
            . self::lengthPrefixed($logicalName);
        return rtrim(strtr(base64_encode(hash('sha256', $material, true)), '+/', '-_'), '=');
    }

    private static function lengthPrefixed(string $value): string
    {
        return strlen($value) . ':' . $value;
    }
}
