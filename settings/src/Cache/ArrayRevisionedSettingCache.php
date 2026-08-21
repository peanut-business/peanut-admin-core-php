<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Cache;

use PeanutAdmin\Settings\Application\EffectiveSetting;

final class ArrayRevisionedSettingCache implements RevisionedSettingCache
{
    /** @var array<string, EffectiveSetting> */
    private array $values = [];

    private int $hitCount = 0;

    public function get(string $key): ?EffectiveSetting
    {
        $value = $this->values[$key] ?? null;
        if ($value !== null) {
            ++$this->hitCount;
        }

        return $value;
    }

    public function put(string $key, EffectiveSetting $setting): void
    {
        $this->values[$key] = $setting;
    }

    public function hits(): int
    {
        return $this->hitCount;
    }
}
