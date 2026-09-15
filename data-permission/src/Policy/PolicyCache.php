<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Policy;

use DateTimeImmutable;
use think\facade\Cache;

final class PolicyCache
{
    public function get(string $key): ?EffectivePolicySet
    {
        $value = Cache::get($this->key($key));
        return $value instanceof EffectivePolicySet ? $value : null;
    }

    public function put(string $key, EffectivePolicySet $policies, ?DateTimeImmutable $nextTransition): void
    {
        $expires = time() + 300;
        if ($nextTransition !== null) {
            $expires = min($expires, $nextTransition->getTimestamp());
        }
        Cache::set($this->key($key), $policies, max(1, $expires - time()));
    }

    private function key(string $key): string
    {
        return 'peanut:data-policy:' . hash('sha256', $key);
    }
}
