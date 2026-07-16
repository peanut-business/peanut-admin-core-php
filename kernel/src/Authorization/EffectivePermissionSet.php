<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization;

final readonly class EffectivePermissionSet
{
    /** @var array<string, true> */
    private array $permissions;

    /** @param iterable<string> $permissions */
    public function __construct(iterable $permissions)
    {
        $indexed = [];
        foreach ($permissions as $permission) {
            $indexed[$permission] = true;
        }

        $this->permissions = $indexed;
    }

    public function allows(string $permissionKey): bool
    {
        return isset($this->permissions[$permissionKey]);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->permissions);
    }
}
