<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Application;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PeanutAdmin\Settings\Cache\ArrayRevisionedSettingCache;
use PeanutAdmin\Settings\Definition\SettingDefinition;
use PeanutAdmin\Settings\Persistence\PdoSettingRepository;
use PeanutAdmin\Settings\Secret\SecretProtector;
use PeanutAdmin\Settings\Secret\SecretStorageContext;

final readonly class SettingAdminService
{
    public function __construct(
        private PdoSettingRepository $repository,
        private SecretProtector $protector,
    ) {}

    public function replaceDeployment(
        SettingDefinition $definition,
        mixed $value,
        int $operatorId,
        DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
        ?string $ifMatch,
        ?string $ifNoneMatch,
        ?DateTimeImmutable $asOf = null,
    ): EffectiveSetting {
        $this->assertInterval($effectiveAt, $expiresAt);
        $storage = $this->storage(
            $definition,
            $value,
            SecretStorageContext::deployment($definition->qualifiedKey()),
        );

        return $this->repository->atomically(function () use (
            $definition,
            $storage,
            $operatorId,
            $effectiveAt,
            $expiresAt,
            $ifMatch,
            $ifNoneMatch,
            $asOf,
        ): EffectiveSetting {
            $this->repository->writeDeployment(
                $definition,
                'set',
                $storage,
                $operatorId,
                $effectiveAt,
                $expiresAt,
                $ifMatch,
                $ifNoneMatch,
            );

            return $this->resolveDeployment($definition, $asOf);
        });
    }

    public function unsetDeployment(
        SettingDefinition $definition,
        int $operatorId,
        DateTimeImmutable $effectiveAt,
        ?string $ifMatch,
        ?DateTimeImmutable $asOf = null,
    ): EffectiveSetting {
        self::assertValidInterval($effectiveAt, null);

        return $this->repository->atomically(function () use (
            $definition,
            $operatorId,
            $effectiveAt,
            $ifMatch,
            $asOf,
        ): EffectiveSetting {
            $this->repository->writeDeployment(
                $definition,
                'unset',
                $this->emptyStorage(),
                $operatorId,
                $effectiveAt,
                null,
                $ifMatch,
                null,
            );

            return $this->resolveDeployment($definition, $asOf);
        });
    }

    public function replaceTenant(
        SettingDefinition $definition,
        int $tenantId,
        int $memberId,
        mixed $value,
        DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
        ?string $ifMatch,
        ?string $ifNoneMatch,
        ?DateTimeImmutable $asOf = null,
    ): EffectiveSetting {
        $this->assertInterval($effectiveAt, $expiresAt);
        $storage = $this->storage(
            $definition,
            $value,
            $definition->secret
                ? SecretStorageContext::tenant($definition->qualifiedKey(), $tenantId)
                : null,
        );

        return $this->repository->atomically(function () use (
            $definition,
            $storage,
            $tenantId,
            $memberId,
            $effectiveAt,
            $expiresAt,
            $ifMatch,
            $ifNoneMatch,
            $asOf,
        ): EffectiveSetting {
            $this->repository->writeTenant(
                $definition,
                'set',
                $storage,
                $tenantId,
                $memberId,
                $effectiveAt,
                $expiresAt,
                $ifMatch,
                $ifNoneMatch,
            );

            return $this->resolveTenant($definition, $tenantId, $asOf);
        });
    }

    public function unsetTenant(
        SettingDefinition $definition,
        int $tenantId,
        int $memberId,
        DateTimeImmutable $effectiveAt,
        ?string $ifMatch,
        ?DateTimeImmutable $asOf = null,
    ): EffectiveSetting {
        self::assertValidInterval($effectiveAt, null);

        return $this->repository->atomically(function () use (
            $definition,
            $tenantId,
            $memberId,
            $effectiveAt,
            $ifMatch,
            $asOf,
        ): EffectiveSetting {
            $this->repository->writeTenant(
                $definition,
                'unset',
                $this->emptyStorage(),
                $tenantId,
                $memberId,
                $effectiveAt,
                null,
                $ifMatch,
                null,
            );

            return $this->resolveTenant($definition, $tenantId, $asOf);
        });
    }

    /** @return array{value_json: ?string, ciphertext: ?string, nonce: ?string, key_id: ?string} */
    public function prepareStorage(
        SettingDefinition $definition,
        mixed $value,
        SecretStorageContext $context,
    ): array {
        return $this->storage($definition, $value, $context);
    }

    /** @param array<string, mixed> $row */
    public function effective(
        SettingDefinition $definition,
        array $row,
        string $source,
        mixed $value,
    ): EffectiveSetting {
        $configured = (string) $row['value_state'] === 'set';
        $revision = (int) $row['revision'];

        return new EffectiveSetting(
            $definition->moduleKey,
            $definition->key,
            $configured ? $value : null,
            $source,
            $configured,
            $revision,
            self::etag($revision),
            $this->apiDate((string) $row['effective_at']),
            $row['expires_at'] === null ? null : $this->apiDate((string) $row['expires_at']),
            $definition->secret,
        );
    }

    public static function assertValidInterval(
        DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
    ): void {
        if (!self::hasExactMillisecondPrecision($effectiveAt)
            || ($expiresAt !== null && !self::hasExactMillisecondPrecision($expiresAt))) {
            throw SettingException::invalid(
                'SETTING_INTERVAL_INVALID',
                'Setting timestamps must use exact millisecond precision.',
            );
        }
        if ($expiresAt !== null && $expiresAt <= $effectiveAt) {
            throw SettingException::invalid(
                'SETTING_INTERVAL_INVALID',
                'The setting expiration must be later than its effective time.',
            );
        }
    }

    /** @return array{value_json: null, ciphertext: null, nonce: null, key_id: null} */
    public static function emptyStorage(): array
    {
        return ['value_json' => null, 'ciphertext' => null, 'nonce' => null, 'key_id' => null];
    }

    private function assertInterval(DateTimeImmutable $effectiveAt, ?DateTimeImmutable $expiresAt): void
    {
        self::assertValidInterval($effectiveAt, $expiresAt);
    }

    private function resolveDeployment(
        SettingDefinition $definition,
        ?DateTimeImmutable $asOf,
    ): EffectiveSetting {
        $resolved = $this->resolver()->resolveDeployment($definition, $this->asOf($asOf));

        return $this->redactSecret($definition, $resolved);
    }

    private function resolveTenant(
        SettingDefinition $definition,
        int $tenantId,
        ?DateTimeImmutable $asOf,
    ): EffectiveSetting {
        $resolved = $this->resolver()->resolveTenant($definition, $tenantId, $this->asOf($asOf));

        return $this->redactSecret($definition, $resolved);
    }

    private function resolver(): SettingResolver
    {
        return new SettingResolver(
            $this->repository,
            $this->protector,
            new ArrayRevisionedSettingCache(),
        );
    }

    private function asOf(?DateTimeImmutable $asOf): DateTimeImmutable
    {
        return $asOf ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function redactSecret(
        SettingDefinition $definition,
        EffectiveSetting $setting,
    ): EffectiveSetting {
        if (!$definition->secret) {
            return $setting;
        }

        return new EffectiveSetting(
            $setting->moduleKey,
            $setting->settingKey,
            null,
            $setting->source,
            $setting->configured,
            $setting->revision,
            $setting->etag,
            $setting->effectiveAt,
            $setting->expiresAt,
            true,
        );
    }

    /** @return array{value_json: ?string, ciphertext: ?string, nonce: ?string, key_id: ?string} */
    private function storage(
        SettingDefinition $definition,
        mixed $value,
        ?SecretStorageContext $context,
    ): array {
        $definition->assertValue($value);
        if ($definition->secret) {
            if (!is_string($value)) {
                throw SettingException::invalid(
                    'SETTING_VALUE_INVALID',
                    'A secret setting requires a string value.',
                );
            }
            if ($context === null) {
                throw SettingException::unavailable(
                    'SETTING_SECRET_UNAVAILABLE',
                    'The setting secret protector is unavailable.',
                );
            }
            $protected = $this->protector->protect($value, $context);

            return [
                'value_json' => null,
                'ciphertext' => $protected['ciphertext'],
                'nonce' => $protected['nonce'],
                'key_id' => $protected['key_id'],
            ];
        }

        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw SettingException::invalid('SETTING_VALUE_INVALID', 'The setting value cannot be encoded.');
        }

        return ['value_json' => $encoded, 'ciphertext' => null, 'nonce' => null, 'key_id' => null];
    }

    private static function etag(int $revision): string
    {
        return '"rev-' . $revision . '"';
    }

    private static function hasExactMillisecondPrecision(DateTimeImmutable $timestamp): bool
    {
        return ((int) $timestamp->format('u')) % 1000 === 0;
    }

    private function apiDate(string $databaseDate): string
    {
        return (new DateTimeImmutable($databaseDate, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }
}
