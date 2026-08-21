<?php
declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization;

final class RegisteredAdminPermissionPolicy
{
    /**
     * @param iterable<string> $registeredPermissions
     * @param iterable<string> $grantedPermissions
     */
    public function canAccess(bool $isRoot, string $accessUri, iterable $registeredPermissions, iterable $grantedPermissions): bool
    {
        $normalized = strtolower(trim($accessUri, '/'));
        $registered = new EffectivePermissionSet($this->normalize($registeredPermissions));
        if (!$registered->allows($normalized)) return false;
        return $isRoot || (new EffectivePermissionSet($this->normalize($grantedPermissions)))->allows($normalized);
    }

    /**
     * @param iterable<string> $permissions
     * @return list<string>
     */
    private function normalize(iterable $permissions): array
    {
        $normalized = [];
        foreach ($permissions as $permission) $normalized[] = strtolower((string) $permission);
        return $normalized;
    }
}
