<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Secret;

use PeanutAdmin\Settings\Application\SettingException;

final readonly class SecretStorageContext
{
    private const AAD_PREFIX = 'peanut-admin/settings/secret/v2';

    private function __construct(
        public string $qualifiedDefinitionKey,
        public string $scope,
        public ?int $tenantId,
        public ?string $targetResourceKey,
        public ?string $targetId,
    ) {}

    public static function deployment(string $qualifiedDefinitionKey): self
    {
        self::assertQualifiedKey($qualifiedDefinitionKey);

        return new self($qualifiedDefinitionKey, 'deployment', null, null, null);
    }

    public static function tenant(string $qualifiedDefinitionKey, int $tenantId): self
    {
        self::assertQualifiedKey($qualifiedDefinitionKey);
        if ($tenantId < 1) {
            throw self::unavailable();
        }

        return new self($qualifiedDefinitionKey, 'tenant', $tenantId, null, null);
    }

    public static function target(
        string $qualifiedDefinitionKey,
        int $tenantId,
        string $targetResourceKey,
        string $targetId,
    ): self {
        self::assertQualifiedKey($qualifiedDefinitionKey);
        if ($tenantId < 1
            || $targetResourceKey === ''
            || strlen($targetResourceKey) > 160
            || $targetId === ''
            || strlen($targetId) > 128) {
            throw self::unavailable();
        }

        return new self(
            $qualifiedDefinitionKey,
            'target',
            $tenantId,
            $targetResourceKey,
            $targetId,
        );
    }

    public function additionalAuthenticatedData(string $keyId): string
    {
        if ($keyId === '' || strlen($keyId) > 64) {
            throw self::unavailable();
        }
        $additionalData = self::AAD_PREFIX;
        foreach ([
            $this->qualifiedDefinitionKey,
            $this->scope,
            $this->tenantId === null ? null : (string) $this->tenantId,
            $this->targetResourceKey,
            $this->targetId,
            $keyId,
        ] as $value) {
            $additionalData .= $value === null
                ? "\x00"
                : "\x01" . pack('N', strlen($value)) . $value;
        }

        return $additionalData;
    }

    private static function assertQualifiedKey(string $qualifiedDefinitionKey): void
    {
        if ($qualifiedDefinitionKey === '' || strlen($qualifiedDefinitionKey) > 256) {
            throw self::unavailable();
        }
    }

    private static function unavailable(): SettingException
    {
        return SettingException::unavailable(
            'SETTING_SECRET_UNAVAILABLE',
            'The setting secret protector is unavailable.',
        );
    }
}
