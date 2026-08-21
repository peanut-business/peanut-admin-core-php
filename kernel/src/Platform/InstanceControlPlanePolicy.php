<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Platform;

final class InstanceControlPlanePolicy
{
    /** @return list<string> */
    public static function tenantAdminPermissions(): array
    {
        return ['storage/lists', 'storage/detail', 'storage/setup', 'storage/change'];
    }
    /** @return list<string> */
    public static function tenantAdminPaths(): array
    {
        return ['/system/storage'];
    }
    public static function isTenantAdminRoute(string $permission): bool
    {
        return in_array(strtolower(trim($permission)), self::tenantAdminPermissions(), true);
    }
}
