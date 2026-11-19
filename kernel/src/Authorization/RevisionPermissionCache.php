<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization;

use Closure;
use think\facade\Cache;

final readonly class RevisionPermissionCache
{
    /**
     * @param null|Closure(string):mixed $getter
     * @param null|Closure(string,mixed,int):void $setter
     */
    public function __construct(
        private ?Closure $getter = null,
        private ?Closure $setter = null,
    ) {}

    public function get(string $audience, string $principalKey, string $revision): ?EffectivePermissionSet
    {
        $cacheKey = $this->key($audience, $principalKey, $revision);
        $value = $this->getter !== null
            ? ($this->getter)($cacheKey)
            : (class_exists(Cache::class) ? Cache::get($cacheKey) : null);

        return $value instanceof EffectivePermissionSet ? $value : null;
    }

    public function put(
        string $audience,
        string $principalKey,
        string $revision,
        EffectivePermissionSet $permissions,
    ): void {
        $cacheKey = $this->key($audience, $principalKey, $revision);
        if ($this->setter !== null) {
            ($this->setter)($cacheKey, $permissions, 300);
            return;
        }
        if (class_exists(Cache::class)) {
            Cache::set($cacheKey, $permissions, 300);
        }
    }

    private function key(string $audience, string $principalKey, string $revision): string
    {
        return 'peanut:authz:' . hash('sha256', "{$audience}\0{$principalKey}\0{$revision}");
    }
}
