<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Application;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PeanutAdmin\Kernel\Persistence\Model\EditionTenantModel;
use PeanutAdmin\Kernel\Persistence\Model\PlatformOperator;
use PeanutAdmin\Kernel\Persistence\Model\TenantMember;
use PeanutAdmin\Kernel\Persistence\Tenancy\TenantPersistenceMode;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Host\AuthorizedExternalOperation;
use PeanutAdmin\Settings\Cache\ArrayRevisionedSettingCache;
use PeanutAdmin\Settings\Definition\SettingDefinition;
use PeanutAdmin\Settings\Model\DeploymentSettingValue;
use PeanutAdmin\Settings\Model\SettingDefinitionRecord;
use PeanutAdmin\Settings\Model\TargetSettingValue;
use PeanutAdmin\Settings\Model\TenantSettingValue;
use PeanutAdmin\Settings\Secret\SecretProtector;
use PeanutAdmin\Settings\Secret\SecretStorageContext;
use think\db\BaseQuery;
use think\db\exception\PDOException;
use think\facade\Db;
use think\Model;

final readonly class SettingAdminService
{
    public function __construct(
        private SecretProtector $protector,
        private TenantPersistenceMode $persistenceMode = TenantPersistenceMode::TenantScoped,
        private ?int $instanceTenantId = null,
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

        return Db::transaction(function () use (
            $definition,
            $storage,
            $operatorId,
            $effectiveAt,
            $expiresAt,
            $ifMatch,
            $ifNoneMatch,
            $asOf,
        ): EffectiveSetting {
            $this->writeDeployment(
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

        return Db::transaction(function () use (
            $definition,
            $operatorId,
            $effectiveAt,
            $ifMatch,
            $asOf,
        ): EffectiveSetting {
            $this->writeDeployment(
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

        return Db::transaction(function () use (
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
            $this->writeTenant(
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

        return Db::transaction(function () use (
            $definition,
            $tenantId,
            $memberId,
            $effectiveAt,
            $ifMatch,
            $asOf,
        ): EffectiveSetting {
            $this->writeTenant(
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

    public function replaceTarget(
        AuthorizedExternalOperation $authorized,
        SettingDefinition $definition,
        mixed $value,
        DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
        ?string $ifMatch,
        ?string $ifNoneMatch,
        ?DateTimeImmutable $asOf = null,
    ): EffectiveSetting {
        [$tenantId, $memberId, $targetResourceKey, $targetId] = $this->target($authorized, $definition);
        self::assertValidInterval($effectiveAt, $expiresAt);
        $storage = $this->storage(
            $definition,
            $value,
            SecretStorageContext::target(
                $definition->qualifiedKey(),
                $tenantId,
                $targetResourceKey,
                $targetId,
            ),
        );

        return Db::transaction(function () use (
            $authorized,
            $definition,
            $storage,
            $tenantId,
            $memberId,
            $targetResourceKey,
            $targetId,
            $effectiveAt,
            $expiresAt,
            $ifMatch,
            $ifNoneMatch,
            $asOf,
        ): EffectiveSetting {
            $this->writeTarget(
                $definition,
                'set',
                $storage,
                $tenantId,
                $memberId,
                $targetResourceKey,
                $targetId,
                $effectiveAt,
                $expiresAt,
                $ifMatch,
                $ifNoneMatch,
            );

            return $this->redactSecret(
                $definition,
                $this->resolver()->resolveTarget($definition, $authorized, $this->asOf($asOf)),
            );
        });
    }

    public function unsetTarget(
        AuthorizedExternalOperation $authorized,
        SettingDefinition $definition,
        DateTimeImmutable $effectiveAt,
        ?string $ifMatch,
        ?DateTimeImmutable $asOf = null,
    ): EffectiveSetting {
        [$tenantId, $memberId, $targetResourceKey, $targetId] = $this->target($authorized, $definition);
        self::assertValidInterval($effectiveAt, null);

        return Db::transaction(function () use (
            $authorized,
            $definition,
            $tenantId,
            $memberId,
            $targetResourceKey,
            $targetId,
            $effectiveAt,
            $ifMatch,
            $asOf,
        ): EffectiveSetting {
            $this->writeTarget(
                $definition,
                'unset',
                self::emptyStorage(),
                $tenantId,
                $memberId,
                $targetResourceKey,
                $targetId,
                $effectiveAt,
                null,
                $ifMatch,
                null,
            );

            return $this->redactSecret(
                $definition,
                $this->resolver()->resolveTarget($definition, $authorized, $this->asOf($asOf)),
            );
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
            $this->protector,
            new ArrayRevisionedSettingCache(),
            $this->persistenceMode,
            $this->instanceTenantId,
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

    /**
     * @param array{value_json:?string,ciphertext:?string,nonce:?string,key_id:?string} $storage
     * @return array<string, mixed>
     */
    private function writeDeployment(
        SettingDefinition $definition,
        string $state,
        array $storage,
        int $operatorId,
        DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
        ?string $ifMatch,
        ?string $ifNoneMatch,
    ): array {
        return $this->writeValue(
            $definition,
            'deployment',
            $state,
            $storage,
            null,
            $operatorId,
            null,
            null,
            $effectiveAt,
            $expiresAt,
            $ifMatch,
            $ifNoneMatch,
        );
    }

    /**
     * @param array{value_json:?string,ciphertext:?string,nonce:?string,key_id:?string} $storage
     * @return array<string, mixed>
     */
    private function writeTenant(
        SettingDefinition $definition,
        string $state,
        array $storage,
        int $tenantId,
        int $memberId,
        DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
        ?string $ifMatch,
        ?string $ifNoneMatch,
    ): array {
        return $this->writeValue(
            $definition,
            'tenant',
            $state,
            $storage,
            $tenantId,
            $memberId,
            null,
            null,
            $effectiveAt,
            $expiresAt,
            $ifMatch,
            $ifNoneMatch,
        );
    }

    /**
     * @param array{value_json:?string,ciphertext:?string,nonce:?string,key_id:?string} $storage
     * @return array<string, mixed>
     */
    private function writeTarget(
        SettingDefinition $definition,
        string $state,
        array $storage,
        int $tenantId,
        int $memberId,
        string $targetResourceKey,
        string $targetId,
        DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
        ?string $ifMatch,
        ?string $ifNoneMatch,
    ): array {
        return $this->writeValue(
            $definition,
            'target',
            $state,
            $storage,
            $tenantId,
            $memberId,
            $targetResourceKey,
            $targetId,
            $effectiveAt,
            $expiresAt,
            $ifMatch,
            $ifNoneMatch,
        );
    }

    /**
     * @param array{value_json:?string,ciphertext:?string,nonce:?string,key_id:?string} $storage
     * @return array<string,mixed>
     */
    private function writeValue(
        SettingDefinition $definition,
        string $scopeName,
        string $state,
        array $storage,
        ?int $tenantId,
        int $actorId,
        ?string $targetResourceKey,
        ?string $targetId,
        DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
        ?string $ifMatch,
        ?string $ifNoneMatch,
    ): array {
        if (!$definition->allows($scopeName) || !in_array($state, ['set', 'unset'], true)) {
            throw SettingException::invalid('SETTING_SCOPE_INVALID', 'The setting does not allow the requested scope.');
        }
        self::assertValidInterval($effectiveAt, $expiresAt);
        $tenantScope = $scopeName === 'deployment'
            ? null
            : $this->tenantScope($tenantId, 'settings-write');
        $this->assertActor($scopeName, $tenantScope, $actorId);
        $definitionRow = $this->definitionRow($definition, true);

        try {
            $existing = $this->currentValue(
                $scopeName,
                (int) $definitionRow['id'],
                $tenantScope,
                $targetResourceKey,
                $targetId,
                true,
            );
            $revision = $this->precondition(
                $existing instanceof Model ? $existing->getData() : null,
                $ifMatch,
                $ifNoneMatch,
            );
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $row = [
                'definition_id' => (int) $definitionRow['id'],
                'value_state' => $state,
                ...$storage,
                'revision' => $revision,
                'effective_at' => self::databaseDate($effectiveAt),
                'expires_at' => $expiresAt === null ? null : self::databaseDate($expiresAt),
                'updated_at' => self::databaseDate($now),
            ];
            if ($scopeName === 'deployment') {
                $row['updated_by_operator_id'] = $actorId;
            } else {
                if (!$tenantScope instanceof TenantScope) {
                    throw SettingException::notFound('SETTING_TARGET_UNAUTHORIZED');
                }
                $row = EditionTenantModel::tenantAttributes(
                    $tenantScope,
                    $this->persistenceMode,
                    $this->instanceTenantId,
                    $row,
                );
                $row['updated_by_member_id'] = $actorId;
            }
            if ($scopeName === 'target') {
                $row['target_resource_key'] = $targetResourceKey;
                $row['target_id'] = $targetId;
            }

            if (!$existing instanceof Model) {
                $row['created_at'] = self::databaseDate($now);
                $record = $this->newValue($scopeName);
                if (!$record->save($row)) {
                    throw SettingException::conflict();
                }
                $row['id'] = (int) $record->getAttr('id');
            } else {
                $affected = $this->valueQuery($scopeName, $tenantScope)
                    ->where('id', (int) $existing->getAttr('id'))
                    ->where('revision', $revision - 1)
                    ->update(array_diff_key($row, array_flip([
                        'definition_id', 'tenant_id', 'target_resource_key', 'target_id', 'created_at',
                    ])));
                if ($affected !== 1) {
                    throw SettingException::revisionMismatch();
                }
                $row['id'] = (int) $existing->getAttr('id');
                $row['created_at'] = $existing->getAttr('created_at');
            }

            return $row;
        } catch (PDOException $exception) {
            $error = $exception->getData()['PDO Error Info'] ?? [];
            $sqlState = (string) ($error['SQLSTATE'] ?? $exception->getCode());
            $driverCode = (int) ($error['Driver Error Code'] ?? 0);
            if (($sqlState === '23000' && $driverCode === 1062)
                || ($sqlState === '40001' && $driverCode === 1213)
                || ($sqlState === 'HY000' && $driverCode === 1205)) {
                throw SettingException::conflict();
            }

            throw $exception;
        }
    }

    private function assertActor(string $scopeName, ?TenantScope $scope, int $actorId): void
    {
        if ($scopeName === 'deployment') {
            $operator = PlatformOperator::where('id', $actorId)->find();
            if (!$operator instanceof PlatformOperator) {
                throw SettingException::notFound('SETTING_ACTOR_UNAUTHORIZED');
            }

            return;
        }
        if (!$scope instanceof TenantScope
            || !TenantMember::scope('tenant', $scope)->where('id', $actorId)->find() instanceof TenantMember) {
            throw SettingException::notFound('SETTING_TARGET_UNAUTHORIZED');
        }
    }

    /** @return array<string,mixed> */
    private function definitionRow(SettingDefinition $definition, bool $lock): array
    {
        $record = SettingDefinitionRecord::where('module_key', $definition->moduleKey)
            ->where('setting_key', $definition->key)
            ->where('status', 'active')
            ->lock($lock ? 'FOR SHARE' : false)
            ->find();
        if (!$record instanceof SettingDefinitionRecord
            || !hash_equals((string) $record->getAttr('definition_digest'), $definition->digest)) {
            throw SettingException::notFound();
        }

        return $record->getData();
    }

    private function currentValue(
        string $scopeName,
        int $definitionId,
        ?TenantScope $scope,
        ?string $targetResourceKey,
        ?string $targetId,
        bool $lock,
    ): ?Model {
        $query = $this->valueQuery($scopeName, $scope)->where('definition_id', $definitionId);
        if ($scopeName === 'target') {
            $query->where('target_resource_key', $targetResourceKey)->where('target_id', $targetId);
        }
        $record = $query->lock($lock)->find();

        return $record instanceof Model ? $record : null;
    }

    private function valueQuery(string $scopeName, ?TenantScope $scope): BaseQuery
    {
        return match ($scopeName) {
            'deployment' => (new DeploymentSettingValue())->db(),
            'tenant' => TenantSettingValue::scope(
                'tenant',
                $scope ?? throw SettingException::notFound(),
                $this->persistenceMode,
                $this->instanceTenantId,
            ),
            'target' => TargetSettingValue::scope(
                'tenant',
                $scope ?? throw SettingException::notFound(),
                $this->persistenceMode,
                $this->instanceTenantId,
            ),
            default => throw SettingException::invalid('SETTING_SCOPE_INVALID', 'The setting scope is invalid.'),
        };
    }

    private function newValue(string $scopeName): Model
    {
        return match ($scopeName) {
            'deployment' => new DeploymentSettingValue(),
            'tenant' => new TenantSettingValue(),
            'target' => new TargetSettingValue(),
            default => throw SettingException::invalid('SETTING_SCOPE_INVALID', 'The setting scope is invalid.'),
        };
    }

    /** @param ?array<string,mixed> $existing */
    private function precondition(?array $existing, ?string $ifMatch, ?string $ifNoneMatch): int
    {
        if ($existing === null) {
            if ($ifMatch === null && $ifNoneMatch === null) {
                throw SettingException::preconditionRequired();
            }
            if ($ifMatch !== null || $ifNoneMatch !== '*') {
                throw SettingException::revisionMismatch();
            }

            return 1;
        }
        if ($ifMatch === null && $ifNoneMatch === null) {
            throw SettingException::preconditionRequired();
        }
        $revision = (int) $existing['revision'];
        if ($ifNoneMatch !== null || $ifMatch !== '"rev-' . $revision . '"') {
            throw SettingException::revisionMismatch();
        }

        return $revision + 1;
    }

    private function tenantScope(?int $tenantId, string $identity): TenantScope
    {
        if (!is_int($tenantId) || $tenantId < 1) {
            throw SettingException::notFound();
        }

        try {
            $scope = TenantScope::fromTrustedContext($tenantId, $identity);
            EditionTenantModel::tenantAttributes(
                $scope,
                $this->persistenceMode,
                $this->instanceTenantId,
                [],
            );

            return $scope;
        } catch (\RuntimeException) {
            throw SettingException::notFound();
        }
    }

    private static function databaseDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    /** @return array{positive-int,positive-int,non-empty-string,non-empty-string} */
    private function target(AuthorizedExternalOperation $authorized, SettingDefinition $definition): array
    {
        $context = $authorized->context;
        $operation = $authorized->operation;
        if (!$context instanceof TenantContext
            || $context->tenantId < 1
            || $context->memberId < 1
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
            || !$operation->atomicCommand
            || !$operation->idempotencyRequired
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

        return [$context->tenantId, $context->memberId, $target->targetResourceKey, $target->targetIds[0]];
    }

    private static function hasExactMillisecondPrecision(DateTimeImmutable $timestamp): bool
    {
        return ((int) $timestamp->format('u')) % 1000 === 0;
    }

}
