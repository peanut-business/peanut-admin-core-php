<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Application;

final readonly class EffectiveSetting
{
    public function __construct(
        public string $moduleKey,
        public string $settingKey,
        public mixed $value,
        public ?string $source,
        public bool $configured,
        public int $revision,
        public ?string $etag,
        public ?string $effectiveAt,
        public ?string $expiresAt,
        public bool $secret,
    ) {}

    /** @return array<string, bool|int|string|null|mixed> */
    public function toAdminArray(): array
    {
        $result = [
            'module_key' => $this->moduleKey,
            'setting_key' => $this->settingKey,
            'configured' => $this->configured,
            'source' => $this->source,
            'revision' => $this->revision,
            'etag' => $this->etag,
            'effective_at' => $this->effectiveAt,
            'expires_at' => $this->expiresAt,
        ];
        if (!$this->secret) {
            $result['value'] = $this->value;
        }

        return $result;
    }
}
