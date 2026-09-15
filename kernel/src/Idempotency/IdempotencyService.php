<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Idempotency;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PeanutAdmin\Kernel\Api\ApiException;
use PeanutAdmin\Kernel\Idempotency\Model\PlatformIdempotencyRecord;
use PeanutAdmin\Kernel\Idempotency\Model\TenantIdempotencyRecord as TenantRecordModel;
use PeanutAdmin\Kernel\Persistence\Model\EditionTenantModel;
use PeanutAdmin\Kernel\Persistence\Tenancy\TenantPersistenceMode;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use think\db\BaseQuery;
use think\db\exception\PDOException;
use think\Model;

final readonly class IdempotencyService
{
    public function __construct(
        private TenantPersistenceMode $persistenceMode = TenantPersistenceMode::TenantScoped,
        private ?int $instanceTenantId = null,
    ) {}

    public function beginTenant(
        TenantScope $scope,
        int $memberId,
        string $operationKey,
        IdempotencyKey $key,
        string $requestHash,
        DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $comparisonTime = null,
    ): IdempotencyRecord {
        return $this->begin(
            $this->tenantQuery($scope)->where('tenant_member_id', $memberId),
            new TenantRecordModel(),
            EditionTenantModel::tenantAttributes(
                $scope,
                $this->persistenceMode,
                $this->instanceTenantId,
                ['tenant_member_id' => $memberId],
            ),
            $operationKey,
            $key,
            $requestHash,
            $expiresAt,
            $comparisonTime,
        );
    }

    public function beginPlatform(
        int $operatorId,
        string $operationKey,
        IdempotencyKey $key,
        string $requestHash,
        DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $comparisonTime = null,
    ): IdempotencyRecord {
        return $this->begin(
            PlatformIdempotencyRecord::where('platform_operator_id', $operatorId),
            new PlatformIdempotencyRecord(),
            ['platform_operator_id' => $operatorId],
            $operationKey,
            $key,
            $requestHash,
            $expiresAt,
            $comparisonTime,
        );
    }

    /** @param array<string,mixed> $responseBody */
    public function completeTenant(
        TenantScope $scope,
        int $id,
        int $status,
        array $responseBody,
        ?string $resourceType = null,
        ?string $resourceId = null,
    ): void
    {
        $this->storeOutcome(
            $this->tenantQuery($scope),
            $id,
            'completed',
            $status,
            $responseBody,
            $resourceType,
            $resourceId,
        );
    }

    /** @param array<string,mixed> $responseBody */
    public function completePlatform(
        int $id,
        int $status,
        array $responseBody,
        ?string $resourceType = null,
        ?string $resourceId = null,
    ): void
    {
        $this->storeOutcome(
            PlatformIdempotencyRecord::where([]),
            $id,
            'completed',
            $status,
            $responseBody,
            $resourceType,
            $resourceId,
        );
    }

    /** @param array<string,mixed> $responseBody */
    public function failTenant(
        TenantScope $scope,
        int $id,
        int $status,
        array $responseBody,
        ?string $resourceType = null,
        ?string $resourceId = null,
    ): void {
        $this->storeOutcome(
            $this->tenantQuery($scope),
            $id,
            'failed',
            $status,
            $responseBody,
            $resourceType,
            $resourceId,
        );
    }

    /** @param array<string,mixed> $responseBody */
    public function failPlatform(
        int $id,
        int $status,
        array $responseBody,
        ?string $resourceType = null,
        ?string $resourceId = null,
    ): void {
        $this->storeOutcome(
            PlatformIdempotencyRecord::where([]),
            $id,
            'failed',
            $status,
            $responseBody,
            $resourceType,
            $resourceId,
        );
    }

    /**
     * @param array<string,int> $identity
     */
    private function begin(
        BaseQuery $query,
        Model $record,
        array $identity,
        string $operationKey,
        IdempotencyKey $key,
        string $requestHash,
        DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $comparisonTime,
    ): IdempotencyRecord {
        $now = ($comparisonTime ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $expiresAt = $expiresAt->setTimezone(new DateTimeZone('UTC'));
        if ($expiresAt <= $now) {
            throw new \InvalidArgumentException('Idempotency expiry must be later than the comparison time.');
        }
        $query->where('operation_key', $operationKey)->where('idempotency_key_hash', $key->hash);
        $known = $query->lock(true)->find();
        if ($known instanceof Model) {
            return $this->existing($known->getData(), $requestHash);
        }

        try {
            $saved = $record->save([
                ...$identity,
                'operation_key' => $operationKey,
                'idempotency_key_hash' => $key->hash,
                'request_hash' => $requestHash,
                'status' => 'processing',
                'expires_at' => self::date($expiresAt),
                'created_at' => self::date($now),
                'updated_at' => self::date($now),
            ]);
            if (!$saved) {
                throw new \RuntimeException('Idempotency record could not be created.');
            }

            return new IdempotencyRecord(
                (int) $record->getAttr('id'),
                'processing',
                $requestHash,
                null,
                null,
                null,
                null,
                true,
            );
        } catch (PDOException $exception) {
            $error = $exception->getData()['PDO Error Info'] ?? [];
            if ((string) ($error['SQLSTATE'] ?? $exception->getCode()) !== '23000'
                || (int) ($error['Driver Error Code'] ?? 0) !== 1062) {
                throw $exception;
            }
            $known = $query->lock(true)->find();
            if (!$known instanceof Model) {
                throw new \RuntimeException('Competing idempotency record could not be loaded.');
            }

            return $this->existing($known->getData(), $requestHash);
        }
    }

    /** @param array<string,mixed> $responseBody */
    private function storeOutcome(
        BaseQuery $query,
        int $id,
        string $state,
        int $responseStatus,
        array $responseBody,
        ?string $resourceType,
        ?string $resourceId,
    ): void {
        try {
            $body = json_encode($responseBody, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new \InvalidArgumentException('Idempotency response is not JSON serializable.', 0, $exception);
        }
        $updated = $query->where('id', $id)->where('status', 'processing')->update([
            'status' => $state,
            'response_status' => $responseStatus,
            'response_body_json' => $body,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'updated_at' => self::date(new DateTimeImmutable('now', new DateTimeZone('UTC'))),
        ]);
        if ($updated !== 1) {
            throw new ApiException('IDEMPOTENCY_STATE_CONFLICT', 409, 'Idempotency record is not processing.');
        }
    }

    /** @param array<string,mixed> $row */
    private function existing(array $row, string $requestHash): IdempotencyRecord
    {
        if (!hash_equals((string) $row['request_hash'], $requestHash)) {
            throw new ApiException('IDEMPOTENCY_KEY_REUSED', 409, 'Idempotency key was reused with another request.');
        }
        $response = null;
        if (is_string($row['response_body_json'] ?? null)) {
            try {
                $decoded = json_decode($row['response_body_json'], true, 512, JSON_THROW_ON_ERROR);
                $response = is_array($decoded) ? $decoded : null;
            } catch (JsonException) {
                throw new ApiException('IDEMPOTENCY_RESPONSE_INVALID', 500, 'Stored idempotency response is invalid.');
            }
        }

        return new IdempotencyRecord(
            (int) $row['id'],
            (string) $row['status'],
            (string) $row['request_hash'],
            $row['response_status'] === null ? null : (int) $row['response_status'],
            $response,
            $row['resource_type'] === null ? null : (string) $row['resource_type'],
            $row['resource_id'] === null ? null : (string) $row['resource_id'],
            false,
        );
    }

    private function tenantQuery(TenantScope $scope): BaseQuery
    {
        return TenantRecordModel::scope(
            'tenant',
            $scope,
            $this->persistenceMode,
            $this->instanceTenantId,
        );
    }

    private static function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
