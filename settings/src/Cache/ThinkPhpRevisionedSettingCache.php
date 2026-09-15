<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Cache;

use PeanutAdmin\Settings\Application\EffectiveSetting;
use think\facade\Cache;

final class ThinkPhpRevisionedSettingCache implements RevisionedSettingCache
{
    public function get(string $key): ?EffectiveSetting
    {
        $value = Cache::get($this->key($key));
        return $value instanceof EffectiveSetting ? $value : null;
    }

    public function put(string $key, EffectiveSetting $setting): void
    {
        Cache::set($this->key($key), $setting, 300);
    }

    private function key(string $key): string
    {
        return 'peanut:setting:' . hash('sha256', $key);
    }
}
