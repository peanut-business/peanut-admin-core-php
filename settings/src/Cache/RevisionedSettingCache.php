<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Cache;

use PeanutAdmin\Settings\Application\EffectiveSetting;

interface RevisionedSettingCache
{
    public function get(string $key): ?EffectiveSetting;

    public function put(string $key, EffectiveSetting $setting): void;
}
