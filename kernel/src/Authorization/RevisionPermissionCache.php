<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization;

use think\facade\Cache;

final class RevisionPermissionCache
{
    public function get(string $audience, string $principalKey, string $revision): ?EffectivePermissionSet
    {
        $value = Cache::get($this->key($audience, $principalKey, $revision));
        return $value instanceof EffectivePermissionSet ? $value : null;
    }

    public function put(
        string $audience,
        string $principalKey,
        string $revision,
        EffectivePermissionSet $permissions,
    ): void {
        Cache::set($this->key($audience, $principalKey, $revision), $permissions, 300);
    }

    private function key(string $audience, string $principalKey, string $revision): string
    {
        return 'peanut:authz:' . hash('sha256', "{$audience}\0{$principalKey}\0{$revision}");
    }
}
