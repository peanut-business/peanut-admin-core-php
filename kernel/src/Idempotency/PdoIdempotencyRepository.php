<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Idempotency;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDOException;
use PeanutAdmin\Kernel\Api\ApiException;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoRepository;
use RuntimeException;
use Throwable;

final class PdoIdempotencyRepository extends PdoRepository
{
    public function beginTenant(
        int $tenantId,
        int $memberId,
        string $operationKey,
        IdempotencyKey $key,
        string $requestHash,
        DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $comparisonTime = null,
    ): IdempotencyRecord {
        return $this->begin(
            'pa_tenant_idempotency_record',
            ['tenant_id' => $tenantId, 'tenant_member_id' => $memberId],
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
            'pa_platform_idempotency_record',
            ['platform_operator_id' => $operatorId],
            $operationKey,
            $key,
            $requestHash,
            $expiresAt,
            $comparisonTime,
        );
    }

    /** @param array<string, mixed> $responseBody */
    public function completeTenant(
        int $id,
        int $responseStatus,
        array $responseBody,
        ?string $resourceType = null,
        ?string $resourceId = null,
    ): void {
        $this->complete('pa_tenant_idempotency_record', $id, $responseStatus, $responseBody, $resourceType, $resourceId);
    }

    /** @param array<string, mixed> $responseBody */
    public function completePlatform(
        int $id,
        int $responseStatus,
        array $responseBody,
        ?string $resourceType = null,
        ?string $resourceId = null,
    ): void {
        $this->complete('pa_platform_idempotency_record', $id, $responseStatus, $responseBody, $resourceType, $resourceId);
    }

    /** @param array<string, mixed> $responseBody */
    public function failTenant(
        int $id,
        int $responseStatus,
        array $responseBody,
        ?string $resourceType = null,
        ?string $resourceId = null,
    ): void {
        $this->storeOutcome(
            'pa_tenant_idempotency_record',
            'failed',
            $id,
            $responseStatus,
            $responseBody,
            $resourceType,
            $resourceId,
        );
    }

    /** @param array<string, mixed> $responseBody */
    public function failPlatform(
        int $id,
        int $responseStatus,
        array $responseBody,
        ?string $resourceType = null,
        ?string $resourceId = null,
    ): void {
        $this->storeOutcome(
            'pa_platform_idempotency_record',
            'failed',
            $id,
            $responseStatus,
            $responseBody,
            $resourceType,
            $resourceId,
        );
    }

    /**
     * @param 'pa_tenant_idempotency_record'|'pa_platform_idempotency_record' $table
     * @param array<string, int> $scope
     */
    private function begin(
        string $table,
        array $scope,
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

        $ownsTransaction = !$this->transactionActive();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $record = $this->acquire(
                $table,
                $scope,
                $operationKey,
                $key,
                $requestHash,
                $expiresAt,
                $now,
            );
            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $record;
        } catch (Throwable $throwable) {
            if ($ownsTransaction && $this->transactionActive()) {
                $this->pdo->rollBack();
            }

            throw $throwable;
        }
    }

    /**
     * @param 'pa_tenant_idempotency_record'|'pa_platform_idempotency_record' $table
     * @param array<string, int> $scope
     */
    private function acquire(
        string $table,
        array $scope,
        string $operationKey,
        IdempotencyKey $key,
        string $requestHash,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
    ): IdempotencyRecord {
        $where = implode(' AND ', array_map(static fn(string $column): string => "{$column} = :{$column}", array_keys($scope)));
        $parameters = [
            ...$scope,
            'operation_key' => $operationKey,
            'idempotency_key_hash' => $key->hash,
        ];
        $columns = [...array_keys($scope), 'operation_key', 'idempotency_key_hash', 'request_hash', 'status', 'expires_at', 'created_at', 'updated_at'];
        $bindings = array_map(static fn(string $column): string => ":{$column}", $columns);
        $known = $this->fetchOne(<<<SQL
SELECT id FROM {$table}
WHERE {$where} AND operation_key = :operation_key AND idempotency_key_hash = :idempotency_key_hash
LIMIT 1
SQL, $parameters);
        if ($known !== null) {
            $row = $this->lockRecord($table, $where, $parameters);
            if ($row === null) {
                throw new RuntimeException('Known idempotency record disappeared before it could be locked.');
            }

            return $this->existing($row, $requestHash);
        }

        try {
            $inserted = $this->execute(sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $table,
                implode(', ', $columns),
                implode(', ', $bindings),
            ), [
                ...$parameters,
                'request_hash' => $requestHash,
                'status' => 'processing',
                'expires_at' => $this->format($expiresAt),
                'created_at' => $this->format($now),
                'updated_at' => $this->format($now),
            ]) === 1;
        } catch (PDOException $exception) {
            if ($exception->getCode() !== '23000' || ($exception->errorInfo[1] ?? null) !== 1062) {
                throw $exception;
            }
            $inserted = false;
        }
        $insertedId = $inserted ? $this->lastInsertId() : null;
        $row = $this->lockRecord($table, $where, $parameters);
        if ($row === null || ($inserted && (int) $row['id'] !== $insertedId)) {
            throw new RuntimeException('Idempotency acquisition did not return its locked record.');
        }
        if ($inserted) {
            return new IdempotencyRecord((int) $insertedId, 'processing', $requestHash, null, null, null, null, true);
        }

        return $this->existing($row, $requestHash);
    }

    /**
     * @param 'pa_tenant_idempotency_record'|'pa_platform_idempotency_record' $table
     * @param array<string, int|string> $parameters
     * @return array<string, mixed>|null
     */
    private function lockRecord(string $table, string $where, array $parameters): ?array
    {
        return $this->fetchOne(<<<SQL
SELECT id, status, request_hash, response_status, response_body_json,
       resource_type, resource_id
FROM {$table}
WHERE {$where} AND operation_key = :operation_key AND idempotency_key_hash = :idempotency_key_hash
FOR UPDATE
SQL, $parameters);
    }

    private function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    /** @phpstan-impure */
    private function transactionActive(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * @param 'pa_tenant_idempotency_record'|'pa_platform_idempotency_record' $table
     * @param array<string, mixed> $responseBody
     */
    private function complete(
        string $table,
        int $id,
        int $responseStatus,
        array $responseBody,
        ?string $resourceType,
        ?string $resourceId,
    ): void {
        $this->storeOutcome($table, 'completed', $id, $responseStatus, $responseBody, $resourceType, $resourceId);
    }

    /**
     * @param 'pa_tenant_idempotency_record'|'pa_platform_idempotency_record' $table
     * @param 'completed'|'failed' $status
     * @param array<string, mixed> $responseBody
     */
    private function storeOutcome(
        string $table,
        string $status,
        int $id,
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
        $updated = $this->execute(<<<SQL
UPDATE {$table}
SET status = :status, response_status = :response_status,
    response_body_json = :response_body, resource_type = :resource_type,
    resource_id = :resource_id, updated_at = :updated_at
WHERE id = :id AND status = 'processing'
SQL, [
            'status' => $status,
            'response_status' => $responseStatus,
            'response_body' => $body,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'updated_at' => $this->now(),
            'id' => $id,
        ]);
        if ($updated !== 1) {
            throw new ApiException('IDEMPOTENCY_STATE_CONFLICT', 409, 'Idempotency record is not processing.');
        }
    }

    /** @param array<string, mixed> $row */
    private function existing(array $row, string $requestHash): IdempotencyRecord
    {
        if (!hash_equals((string) $row['request_hash'], $requestHash)) {
            throw new ApiException('IDEMPOTENCY_KEY_REUSED', 409, 'Idempotency key was reused with another request.');
        }
        $response = null;
        if (is_string($row['response_body_json'])) {
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
}
