<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Idempotency;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PeanutAdmin\Kernel\Api\ApiException;
use PeanutAdmin\Kernel\Idempotency\Model\PlatformIdempotencyRecord;
use PeanutAdmin\Kernel\Idempotency\Model\TenantIdempotencyRecord as TenantRecordModel;
use PeanutAdmin\Kernel\Persistence\Model\TenantModel;
use PeanutAdmin\Kernel\Persistence\Tenancy\TenantColumnScope;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use think\db\BaseQuery;
use think\db\exception\PDOException;
use think\Model;
use think\model\type\Json;

final readonly class IdempotencyService
{
    private TenantColumnScope $tenantScope;

    public function __construct(?TenantColumnScope $tenantScope = null)
    {
        $this->tenantScope = $tenantScope ?? TenantColumnScope::shared();
    }

    public function findCompletedTenant(
        TenantScope $scope,
        int $memberId,
        string $operationKey,
        IdempotencyKey $key,
        string $requestHash,
        ?DateTimeImmutable $comparisonTime = null,
    ): ?IdempotencyRecord {
        $known = $this->tenantQuery($scope)
            ->where('tenant_member_id', $memberId)
            ->where('operation_key', $operationKey)
            ->where('idempotency_key_hash', $key->hash)
            ->find();
        if (!$known instanceof Model) {
            return null;
        }
        $data = $known->getData();
        $now = ($comparisonTime ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        if ((string) ($data['status'] ?? '') !== 'completed' || self::isExpired($data, $now)) {
            return null;
        }

        return $this->existing($data, $requestHash);
    }

    public function findCompletedPlatform(
        int $operatorId,
        string $operationKey,
        IdempotencyKey $key,
        string $requestHash,
        ?DateTimeImmutable $comparisonTime = null,
    ): ?IdempotencyRecord {
        $known = PlatformIdempotencyRecord::where('platform_operator_id', $operatorId)
            ->where('operation_key', $operationKey)
            ->where('idempotency_key_hash', $key->hash)
            ->find();
        if (!$known instanceof Model) {
            return null;
        }
        $data = $known->getData();
        $now = ($comparisonTime ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        if ((string) ($data['status'] ?? '') !== 'completed' || self::isExpired($data, $now)) {
            return null;
        }

        return $this->existing($data, $requestHash);
    }

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
            TenantModel::tenantAttributes(
                $scope,
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
    ): void {
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
    ): void {
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
        $known = (clone $query)->find();
        if ($known instanceof Model) {
            $knownData = $known->getData();
            if ((string) ($knownData['status'] ?? '') === 'completed' && !self::isExpired($knownData, $now)) {
                return $this->existing($knownData, $requestHash);
            }
            $locked = (clone $query)->lock(true)->find();
            if (!$locked instanceof Model) {
                throw new \RuntimeException('Known idempotency record disappeared before it could be locked.');
            }
            $lockedData = $locked->getData();
            if (self::isExpired($lockedData, $now)) {
                return $this->reclaimExpired($query, $lockedData, $requestHash, $expiresAt, $now);
            }

            return $this->existing($lockedData, $requestHash);
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
            $known = (clone $query)->lock(true)->find();
            if (!$known instanceof Model) {
                throw new \RuntimeException('Competing idempotency record could not be loaded.');
            }
            $lockedData = $known->getData();
            if (self::isExpired($lockedData, $now)) {
                return $this->reclaimExpired($query, $lockedData, $requestHash, $expiresAt, $now);
            }

            return $this->existing($lockedData, $requestHash);
        }
    }

    /** @param array<string,mixed> $lockedData */
    private function reclaimExpired(
        BaseQuery $query,
        array $lockedData,
        string $requestHash,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
    ): IdempotencyRecord {
        $id = (int) $lockedData['id'];
        (clone $query)->where('id', $id)->update([
            'request_hash' => $requestHash,
            'status' => 'processing',
            'response_status' => null,
            'response_body_json' => null,
            'resource_type' => null,
            'resource_id' => null,
            'expires_at' => self::date($expiresAt),
            'updated_at' => self::date($now),
        ]);

        return new IdempotencyRecord(
            $id,
            'processing',
            $requestHash,
            null,
            null,
            null,
            null,
            true,
        );
    }

    /** @param array<string,mixed> $row */
    private static function isExpired(array $row, DateTimeImmutable $now): bool
    {
        $raw = $row['expires_at'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return false;
        }
        try {
            $expiry = new DateTimeImmutable($raw, new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return false;
        }

        return $expiry <= $now;
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
        $storedResponse = $row['response_body_json'] ?? null;
        if ($storedResponse instanceof Json) {
            $storedResponse = $storedResponse->value();
        }
        if (is_string($storedResponse)) {
            try {
                $storedResponse = json_decode($storedResponse, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new ApiException('IDEMPOTENCY_RESPONSE_INVALID', 500, 'Stored idempotency response is invalid.');
            }
        }
        if ($storedResponse !== null) {
            if (!is_array($storedResponse)) {
                throw new ApiException('IDEMPOTENCY_RESPONSE_INVALID', 500, 'Stored idempotency response is invalid.');
            }
            $response = $storedResponse;
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
        );
    }

    private static function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
