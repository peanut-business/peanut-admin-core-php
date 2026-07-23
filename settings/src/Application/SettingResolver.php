<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Application;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Host\AuthorizedExternalOperation;
use PeanutAdmin\Settings\Cache\RevisionedSettingCache;
use PeanutAdmin\Settings\Definition\SettingDefinition;
use PeanutAdmin\Settings\Persistence\PdoSettingRepository;
use PeanutAdmin\Settings\Secret\SecretProtector;
use PeanutAdmin\Settings\Secret\SecretStorageContext;
use Throwable;

final readonly class SettingResolver
{
    private SettingAdminService $admin;

    public function __construct(
        private PdoSettingRepository $repository,
        private SecretProtector $protector,
        private RevisionedSettingCache $cache,
    ) {
        $this->admin = new SettingAdminService($repository, $protector);
    }

    public function resolveTenant(
        SettingDefinition $definition,
        int $tenantId,
        DateTimeImmutable $asOf,
    ): EffectiveSetting {
        if ($tenantId < 1) {
            throw SettingException::notFound();
        }
        $snapshot = $this->repository->resolutionSnapshot($definition, $tenantId);

        return $this->resolve($definition, $tenantId, null, null, 'tenant', $asOf, $snapshot);
    }

    public function resolveDeployment(
        SettingDefinition $definition,
        DateTimeImmutable $asOf,
    ): EffectiveSetting {
        $snapshot = $this->repository->deploymentSnapshot($definition);

        return $this->resolve($definition, null, null, null, 'deployment', $asOf, [
            'definition' => $snapshot['definition'],
            'deployment' => $snapshot['deployment'],
            'tenant' => null,
            'target' => null,
        ]);
    }

    public function resolveTarget(
        SettingDefinition $definition,
        AuthorizedExternalOperation $authorized,
        DateTimeImmutable $asOf,
    ): EffectiveSetting {
        [$tenantId, $targetResourceKey, $targetId] = $this->target(
            $authorized,
            $definition,
        );
        $snapshot = $this->repository->resolutionSnapshot(
            $definition,
            $tenantId,
            $targetResourceKey,
            $targetId,
        );

        return $this->resolve(
            $definition,
            $tenantId,
            $targetResourceKey,
            $targetId,
            'target',
            $asOf,
            $snapshot,
        );
    }

    /**
     * @param array{
     *     definition: array<string, mixed>,
     *     deployment: array<string, mixed>|null,
     *     tenant: array<string, mixed>|null,
     *     target: array<string, mixed>|null
     * } $snapshot
     */
    private function resolve(
        SettingDefinition $definition,
        ?int $tenantId,
        ?string $targetResourceKey,
        ?string $targetId,
        string $managedScope,
        DateTimeImmutable $asOf,
        array $snapshot,
    ): EffectiveSetting {
        $validatedValues = $this->validateSnapshot(
            $definition,
            $tenantId,
            $targetResourceKey,
            $targetId,
            $snapshot,
        );
        [$managedRevision, $managedEtag] = $this->managedPrecondition(
            $snapshot['definition'],
            $snapshot[$managedScope],
        );
        $cacheKey = null;
        if (!$definition->secret) {
            $cacheKey = $this->cacheKey(
                $definition,
                $tenantId,
                $targetResourceKey,
                $targetId,
                $asOf,
                $snapshot,
            );
            try {
                $cached = $this->cache->get($cacheKey);
            } catch (Throwable) {
                $cached = null;
            }
            if ($cached !== null) {
                return $cached;
            }
        }

        foreach (['target', 'tenant', 'deployment'] as $scope) {
            $row = $snapshot[$scope];
            if ($row === null) {
                continue;
            }
            $resolved = $this->resolveRow(
                $definition,
                $row,
                $scope,
                $asOf,
                $validatedValues[$scope],
            );
            if ($resolved !== null) {
                $resolved = $this->withPrecondition($resolved, $managedRevision, $managedEtag);
                $this->cache($cacheKey, $resolved);

                return $resolved;
            }
        }

        if ($definition->hasDefault) {
            $definition->assertValue($definition->defaultValue, 'SETTING_STORED_VALUE_INVALID');
            $resolved = new EffectiveSetting(
                $definition->moduleKey,
                $definition->key,
                $definition->defaultValue,
                'default',
                false,
                $managedRevision,
                $managedEtag,
                null,
                null,
                $definition->secret,
            );
            $this->cache($cacheKey, $resolved);

            return $resolved;
        }
        if ($definition->required) {
            throw SettingException::unavailable(
                'SETTING_REQUIRED_VALUE_MISSING',
                'A required setting has no effective value.',
            );
        }

        $resolved = new EffectiveSetting(
            $definition->moduleKey,
            $definition->key,
            null,
            null,
            false,
            $managedRevision,
            $managedEtag,
            null,
            null,
            $definition->secret,
        );
        $this->cache($cacheKey, $resolved);

        return $resolved;
    }

    /**
     * @param array{
     *     definition: array<string, mixed>,
     *     deployment: array<string, mixed>|null,
     *     tenant: array<string, mixed>|null,
     *     target: array<string, mixed>|null
     * } $snapshot
     * @return array{target: mixed, tenant: mixed, deployment: mixed}
     */
    private function validateSnapshot(
        SettingDefinition $definition,
        ?int $tenantId,
        ?string $targetResourceKey,
        ?string $targetId,
        array $snapshot,
    ): array {
        $values = ['target' => null, 'tenant' => null, 'deployment' => null];
        foreach (['target', 'tenant', 'deployment'] as $scope) {
            $row = $snapshot[$scope];
            if ($row === null) {
                continue;
            }
            $state = $row['value_state'] ?? null;
            if (!in_array($state, ['set', 'unset'], true)) {
                throw $this->storedValueInvalid();
            }
            $effectiveAt = $this->databaseDate($row['effective_at'] ?? null);
            $expiresAt = ($row['expires_at'] ?? null) === null
                ? null
                : $this->databaseDate($row['expires_at']);
            if ($expiresAt !== null && $expiresAt <= $effectiveAt) {
                throw $this->storedValueInvalid();
            }
            if ($state === 'set') {
                $values[$scope] = $this->storedValue(
                    $definition,
                    $row,
                    $this->secretContext(
                        $definition,
                        $scope,
                        $tenantId,
                        $targetResourceKey,
                        $targetId,
                    ),
                );
                continue;
            }
            foreach (['value_json', 'ciphertext', 'nonce', 'key_id'] as $column) {
                if (!array_key_exists($column, $row) || $row[$column] !== null) {
                    throw $this->storedValueInvalid();
                }
            }
        }

        return $values;
    }

    /** @param array<string, mixed> $row */
    private function resolveRow(
        SettingDefinition $definition,
        array $row,
        string $scope,
        DateTimeImmutable $asOf,
        mixed $value,
    ): ?EffectiveSetting {
        $state = $row['value_state'] ?? null;
        if (!in_array($state, ['set', 'unset'], true)) {
            throw $this->storedValueInvalid();
        }
        $effectiveAt = $this->databaseDate($row['effective_at'] ?? null);
        $expiresAt = ($row['expires_at'] ?? null) === null
            ? null
            : $this->databaseDate($row['expires_at']);
        if ($expiresAt !== null && $expiresAt <= $effectiveAt) {
            throw $this->storedValueInvalid();
        }
        $asOf = $asOf->setTimezone(new DateTimeZone('UTC'));
        if ($state === 'unset' || $asOf < $effectiveAt || ($expiresAt !== null && $asOf >= $expiresAt)) {
            return null;
        }

        return $this->admin->effective($definition, $row, $scope, $value);
    }

    /** @param array<string, mixed> $definitionRow
     * @param array<string, mixed>|null $managedRow
     * @return array{positive-int, non-empty-string|null}
     */
    private function managedPrecondition(array $definitionRow, ?array $managedRow): array
    {
        [, $revision] = $this->revision($managedRow ?? $definitionRow);

        return [$revision, $managedRow === null ? null : '"rev-' . $revision . '"'];
    }

    private function withPrecondition(
        EffectiveSetting $setting,
        int $revision,
        ?string $etag,
    ): EffectiveSetting {
        return new EffectiveSetting(
            $setting->moduleKey,
            $setting->settingKey,
            $setting->value,
            $setting->source,
            $setting->configured,
            $revision,
            $etag,
            $setting->effectiveAt,
            $setting->expiresAt,
            $setting->secret,
        );
    }

    /** @param array<string, mixed> $row */
    private function storedValue(
        SettingDefinition $definition,
        array $row,
        SecretStorageContext $context,
    ): mixed {
        if ($definition->secret) {
            if (!array_key_exists('value_json', $row)
                || $row['value_json'] !== null
                || !is_string($row['ciphertext'] ?? null)
                || !is_string($row['nonce'] ?? null)
                || !is_string($row['key_id'] ?? null)) {
                throw $this->storedValueInvalid();
            }
            $value = $this->protector->reveal(
                $row['ciphertext'],
                $row['nonce'],
                $row['key_id'],
                $context,
            );
            $definition->assertValue($value, 'SETTING_STORED_VALUE_INVALID');

            return $value;
        }

        if (!is_string($row['value_json'] ?? null)
            || !array_key_exists('ciphertext', $row)
            || $row['ciphertext'] !== null
            || !array_key_exists('nonce', $row)
            || $row['nonce'] !== null
            || !array_key_exists('key_id', $row)
            || $row['key_id'] !== null) {
            throw $this->storedValueInvalid();
        }
        try {
            $value = json_decode($row['value_json'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->storedValueInvalid();
        }
        $definition->assertValue($value, 'SETTING_STORED_VALUE_INVALID');

        return $value;
    }

    private function secretContext(
        SettingDefinition $definition,
        string $scope,
        ?int $tenantId,
        ?string $targetResourceKey,
        ?string $targetId,
    ): SecretStorageContext {
        return match ($scope) {
            'deployment' => SecretStorageContext::deployment($definition->qualifiedKey()),
            'tenant' => SecretStorageContext::tenant(
                $definition->qualifiedKey(),
                $tenantId ?? 0,
            ),
            'target' => SecretStorageContext::target(
                $definition->qualifiedKey(),
                $tenantId ?? 0,
                $targetResourceKey ?? '',
                $targetId ?? '',
            ),
            default => throw $this->storedValueInvalid(),
        };
    }

    /**
     * @param array{
     *     definition: array<string, mixed>,
     *     deployment: array<string, mixed>|null,
     *     tenant: array<string, mixed>|null,
     *     target: array<string, mixed>|null
     * } $snapshot
     */
    private function cacheKey(
        SettingDefinition $definition,
        ?int $tenantId,
        ?string $targetResourceKey,
        ?string $targetId,
        DateTimeImmutable $asOf,
        array $snapshot,
    ): string {
        $definitionRevision = $this->revision($snapshot['definition']);
        $asOf = $asOf->setTimezone(new DateTimeZone('UTC'));
        $candidates = [
            'deployment' => $this->cacheCandidate($snapshot['deployment'], $asOf),
            'tenant' => $this->cacheCandidate($snapshot['tenant'], $asOf),
            'target' => $this->cacheCandidate($snapshot['target'], $asOf),
        ];
        try {
            $payload = json_encode([
                'qualified_key' => $definition->qualifiedKey(),
                'tenant_id' => $tenantId,
                'target_resource_key' => $targetResourceKey,
                'target_id' => $targetId,
                'definition_revision' => $definitionRevision,
                'candidates' => $candidates,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw $this->storedValueInvalid();
        }

        return 'settings:v1:' . hash('sha256', $payload);
    }

    /** @param array<string, mixed>|null $row
     * @return array{revision: array{positive-int, positive-int}, phase: string}|null
     */
    private function cacheCandidate(?array $row, DateTimeImmutable $asOf): ?array
    {
        if ($row === null) {
            return null;
        }
        $state = $row['value_state'] ?? null;
        if (!in_array($state, ['set', 'unset'], true)) {
            throw $this->storedValueInvalid();
        }
        if ($state === 'unset') {
            $phase = 'unset';
        } else {
            $effectiveAt = $this->databaseDate($row['effective_at'] ?? null);
            $expiresAt = ($row['expires_at'] ?? null) === null
                ? null
                : $this->databaseDate($row['expires_at']);
            $phase = match (true) {
                $asOf < $effectiveAt => 'future',
                $expiresAt !== null && $asOf >= $expiresAt => 'expired',
                default => 'active',
            };
        }

        return ['revision' => $this->revision($row), 'phase' => $phase];
    }

    /** @param array<string, mixed> $row
     * @return array{positive-int, positive-int}
     */
    private function revision(array $row): array
    {
        $id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT);
        $revision = filter_var($row['revision'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($id) || $id < 1 || !is_int($revision) || $revision < 1) {
            throw $this->storedValueInvalid();
        }

        return [$id, $revision];
    }

    private function databaseDate(mixed $value): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw $this->storedValueInvalid();
        }
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.v',
            $value,
            new DateTimeZone('UTC'),
        );
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw $this->storedValueInvalid();
        }

        return $date;
    }

    private function cache(?string $key, EffectiveSetting $setting): void
    {
        if ($key === null) {
            return;
        }
        try {
            $this->cache->put($key, $setting);
        } catch (Throwable) {
        }
    }

    /** @return array{positive-int, non-empty-string, non-empty-string} */
    private function target(
        AuthorizedExternalOperation $authorized,
        SettingDefinition $definition,
    ): array {
        $context = $authorized->context;
        $operation = $authorized->operation;
        if (!$context instanceof TenantContext
            || $context->tenantId < 1
            || !$definition->allows('target')
            || $definition->targetResourceKey === null
            || $definition->targetResourceKey === ''
            || $definition->targetOperation === null
            || $operation->audience !== 'tenant'
            || $operation->moduleKey !== $definition->moduleKey
            || $operation->operationId !== $definition->targetOperation
            || $operation->resourceKey !== $definition->targetResourceKey
            || $operation->dataAuthorization !== 'targets'
            || !in_array($operation->targetCardinality, ['one_required', 'zero_or_one'], true)
            || count($authorized->targets) !== 1) {
            throw SettingException::notFound('SETTING_TARGET_UNAUTHORIZED');
        }

        $target = $authorized->targets[0];
        if ($target->targetResourceKey !== $definition->targetResourceKey
            || count($target->targetIds) !== 1
            || $target->targetIds[0] === ''
            || strlen($target->targetIds[0]) > 128) {
            throw SettingException::notFound('SETTING_TARGET_UNAUTHORIZED');
        }

        return [$context->tenantId, $target->targetResourceKey, $target->targetIds[0]];
    }

    private function storedValueInvalid(): SettingException
    {
        return SettingException::invalid(
            'SETTING_STORED_VALUE_INVALID',
            'The stored setting value is invalid.',
        );
    }
}
